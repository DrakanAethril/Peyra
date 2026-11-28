<?php

declare(strict_types=1);

namespace App\Tests\Service\Dossier\Library;

use App\Entity\Accounting\Entry;
use App\Entity\Accounting\Journal;
use App\Entity\Audit\AuditEvent;
use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierShare;
use App\Entity\Identity\User;
use App\Enum\Audit\AuditAction;
use App\Enum\Dossier\DossierKind;
use App\Enum\Dossier\TemplateLevel;
use App\Enum\Dossier\TemplateShelf;
use App\Enum\Identity\AccountKind;
use App\Enum\Identity\EntryBasis;
use App\Enum\Shared\BtsProcess;
use App\Repository\Dossier\DossierShareRepository;
use App\Service\Dossier\CompanyDraft;
use App\Service\Dossier\DossierContext;
use App\Service\Dossier\Library\EmptyTemplateDraft;
use App\Service\Dossier\Library\TemplateCard;
use App\Service\Dossier\Library\TemplateCardDraft;
use App\Service\Dossier\Library\TemplateCards;
use App\Service\Dossier\Library\TemplateDeletion;
use App\Service\Dossier\Library\TemplateDuplicator;
use App\Service\Dossier\Library\TemplateFactory;
use App\Service\Dossier\Library\TemplateLibrary;
use App\Service\Dossier\Library\TemplateShares;
use App\Tests\Support\BuildsLedger;
use App\Tests\Support\DossierFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The library of templates (lot 1, step 1.8a): the shelves a teacher reads, a template's card, a
 * new empty template, a duplicate, the shares and a deletion - each gesture in the template's trail.
 */
final class TemplateLibraryTest extends KernelTestCase
{
    use BuildsLedger;

    private EntityManagerInterface $entityManager;
    private User $durand;
    private User $martin;
    private \DateTimeImmutable $at;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->at = new \DateTimeImmutable('2026-11-28 10:00');
        $this->durand = $this->account(AccountKind::Teacher, 'durand');
        $this->martin = $this->account(AccountKind::Teacher, 'martin');
    }

    public function testEachTeacherReadsTheirShelves(): void
    {
        $mine = $this->template($this->durand);
        $hers = $this->template($this->martin);
        $common = $this->template(null);
        $secret = $this->template($this->martin);
        $this->entityManager->persist(new DossierShare($hers, $this->durand, true, $this->at));
        $student = $this->account(AccountKind::Student, 'manon');
        $copy = $this->savedDossier(DossierKind::Student, $student);
        $copy->comesFrom($common, $this->durand);
        $this->entityManager->flush();

        $library = self::getContainer()->get(TemplateLibrary::class);
        $cards = $library->cards($this->durand);

        self::assertSame([[$mine, TemplateShelf::Mine], [$hers, TemplateShelf::Shared], [$common, TemplateShelf::Common]], array_map(static fn (TemplateCard $card): array => [$card->template, $card->shelf], $cards));
        self::assertTrue($cards[1]->sharedForChange);
        self::assertSame(1, $cards[2]->copies, 'Un dossier d’étudiant fait depuis le modèle.');
        self::assertSame('2026', $cards[0]->exercise?->getCode());
        self::assertNull($library->card($this->durand, $secret));
        self::assertSame(1, $library->cards($this->martin)[0]->shares + $library->cards($this->martin)[1]->shares, 'Son modèle partagé avec un collègue.');
    }

    public function testACardIsCheckedThenSavedWithALineOfTheTrail(): void
    {
        $template = $this->template($this->durand);
        $cards = self::getContainer()->get(TemplateCards::class);
        $draft = new TemplateCardDraft();
        $draft->title = str_repeat('x', 121);
        $draft->level = 'third';
        $draft->processes = ['P1', 'P9'];
        self::assertFalse($cards->check($draft));
        self::assertSame(['title', 'level', 'processes'], array_keys($draft->errors));

        $draft = new TemplateCardDraft();
        $draft->title = 'Vasseur, à-nouveaux 2026';
        $draft->level = 'first';
        $draft->processes = ['P2', 'P1'];
        $draft->description = 'Le premier trimestre.';
        self::assertTrue($cards->save($template, $draft, $this->durand));
        $this->entityManager->flush();
        self::assertFalse($cards->save($template, $draft, $this->durand), 'Rien n’a changé.');

        self::assertSame('Vasseur, à-nouveaux 2026', $template->getLibraryTitle());
        self::assertSame(TemplateLevel::First, $template->getLibraryLevel());
        self::assertSame([BtsProcess::P1, BtsProcess::P2], $template->getLibraryProcesses(), 'Dans l’ordre du référentiel.');
        self::assertCount(1, $this->trail($template, AuditAction::TemplateCardChanged));

        $empty = new TemplateCardDraft();
        $cards->save($template, $empty, $this->durand);
        self::assertSame('Menuiserie Vasseur SARL', $template->getLibraryTitle(), 'Sans titre, le nom de la société.');
    }

    public function testAnEmptyTemplateHasItsExerciseItsPlanAndFiveJournals(): void
    {
        $factory = self::getContainer()->get(TemplateFactory::class);
        $draft = $this->emptyDraft();
        $draft->exerciseStart = '2026-07-01';
        $draft->exerciseEnd = '2026-06-30';
        $draft->dossierDate = '2027-01-01';
        $draft->chart = 'pcg-1999';
        self::assertNull($factory->create($draft, $this->durand, $this->at));
        self::assertSame(['exerciseEnd', 'dossierDate', 'chart'], array_keys($draft->errors));

        $draft = $this->emptyDraft();
        $template = $factory->create($draft, $this->durand, $this->at);
        self::assertInstanceOf(Dossier::class, $template);
        self::assertSame(DossierKind::Template, $template->getKind());
        self::assertSame($this->durand, $template->getOwner());
        self::assertSame('Boulangerie Ravel, création', $template->getLibraryTitle());
        self::assertSame('pcg-2026', $template->getChartCode());
        self::assertSame('2026-09-01', $template->getDossierDate()->format('Y-m-d'));
        [$journals, $exercises] = self::getContainer()->get(DossierContext::class)->within($template, fn (): array => [
            $this->entityManager->getRepository(Journal::class)->findBy([], ['code' => 'ASC']),
            $this->entityManager->getRepository(\App\Entity\Dossier\Exercise::class)->findAll(),
        ]);
        self::assertSame(['ACH', 'AN', 'BQ', 'OD', 'VTE'], array_map(static fn (Journal $journal): string => $journal->getCode(), $journals));
        self::assertSame('512000', $journals[2]->getCounterpartAccount()?->getNumber());
        self::assertSame(['2026-2027'], array_map(static fn ($exercise): string => $exercise->getCode(), $exercises));
    }

    public function testADuplicateIsItsAuthorsDeepCopyWithoutTheShares(): void
    {
        $source = $this->template($this->martin);
        $this->installPlan($source);
        $this->prepareLedger($source);
        $this->entry($source, 'ACH', '2026-01-12', 'F-001', ['601000 D 100.00', '401000/F0001 C 100.00'], true);
        $this->entityManager->persist(new DossierShare($source, $this->durand, false, $this->at));
        $source->describeInLibrary('Exercice de rentrée', 'Un mois d’achats.', TemplateLevel::First, [BtsProcess::P1]);
        $this->entityManager->flush();
        self::getContainer()->get(DossierContext::class)->leave();

        $copy = self::getContainer()->get(TemplateDuplicator::class)->duplicate($source, $this->durand, $this->at);

        self::assertSame(DossierKind::Template, $copy->getKind());
        self::assertSame($this->durand, $copy->getOwner());
        self::assertSame($source, $copy->getSourceTemplate());
        self::assertSame('Exercice de rentrée (copie)', $copy->getLibraryTitle());
        self::assertSame([BtsProcess::P1], $copy->getLibraryProcesses());
        self::assertEquals($source->getDossierDate(), $copy->getDossierDate());
        self::assertSame([], self::getContainer()->get(DossierShareRepository::class)->findByDossier($copy));
        $numbers = self::getContainer()->get(DossierContext::class)->within($copy, fn (): array => array_map(static fn (Entry $entry): string => $entry->getNumberLabel(), $this->entityManager->getRepository(Entry::class)->findAll()));
        self::assertSame(['ACH-2026-00001'], $numbers);
        self::assertCount(1, $this->trail($copy, AuditAction::DossierCreated));
    }

    public function testTheAuthorSharesChangesTheRightAndTakesItBack(): void
    {
        $template = $this->template($this->durand);
        $shares = self::getContainer()->get(TemplateShares::class);
        $student = $this->account(AccountKind::Student, 'manon');

        self::assertSame('Choisissez un enseignant dans la liste.', $shares->share($template, $student, false, $this->durand, $this->at));
        self::assertSame('Ce modèle est déjà le vôtre.', $shares->share($template, $this->durand, false, $this->durand, $this->at));
        self::assertSame('Ce modèle ne se partage pas.', $shares->share($this->template(null), $this->martin, false, $this->durand, $this->at));
        self::assertNull($shares->share($template, $this->martin, false, $this->durand, $this->at));
        $this->entityManager->flush();
        self::assertNull($shares->share($template, $this->martin, true, $this->durand, $this->at));
        $this->entityManager->flush();

        $repository = self::getContainer()->get(DossierShareRepository::class);
        $share = $repository->findOneFor($template, $this->martin);
        self::assertInstanceOf(DossierShare::class, $share);
        self::assertTrue($share->canEdit());
        $shares->revoke($share, $this->durand);
        $this->entityManager->flush();
        self::assertNull($repository->findOneFor($template, $this->martin));
        self::assertCount(2, $this->trail($template, AuditAction::TemplateShared));
        self::assertCount(1, $this->trail($template, AuditAction::TemplateUnshared));
    }

    public function testDeletingATemplateLeavesTheCopiesMadeFromIt(): void
    {
        $template = $this->template($this->durand);
        $template->describeInLibrary('Rentrée', null, null, []);
        $this->installPlan($template);
        $student = $this->account(AccountKind::Student, 'manon');
        $copy = $this->savedDossier(DossierKind::Student, $student);
        $copy->comesFrom($template, $this->durand);
        $this->entityManager->flush();
        $id = (int) $template->getId();
        $deletion = self::getContainer()->get(TemplateDeletion::class);

        self::assertSame('Tapez « Rentrée » exactement pour supprimer ce modèle.', $deletion->delete($template, 'rentrée', $this->durand));
        self::assertNull($deletion->delete($template, ' Rentrée ', $this->durand));

        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Dossier::class, $id));
        $copy = $this->entityManager->find(Dossier::class, $copy->getId());
        self::assertInstanceOf(Dossier::class, $copy);
        self::assertNull($copy->getSourceTemplate());
        self::assertCount(1, $this->entityManager->getRepository(AuditEvent::class)->findBy(['dossierId' => $id, 'action' => AuditAction::TemplateDeleted]));
    }

    private function template(?User $author): Dossier
    {
        return $this->savedDossier(DossierKind::Template, $author);
    }

    private function emptyDraft(): EmptyTemplateDraft
    {
        $draft = new EmptyTemplateDraft();
        $draft->title = 'Boulangerie Ravel, création';
        $company = CompanyDraft::of(DossierFixtures::company('Boulangerie Ravel'));
        $draft->company = $company;
        $draft->exerciseStart = '2026-09-01';
        $draft->exerciseEnd = '2027-08-31';
        $draft->dossierDate = '2026-09-01';
        $draft->chart = 'pcg-2026';

        return $draft;
    }

    /** @return list<AuditEvent> */
    private function trail(Dossier $dossier, AuditAction $action): array
    {
        return $this->entityManager->getRepository(AuditEvent::class)->findBy(['dossierId' => $dossier->getId(), 'action' => $action]);
    }

    private function account(AccountKind $kind, string $login): User
    {
        $student = AccountKind::Student === $kind;
        $user = new User('uid:'.$login, $login, ucfirst($login), $kind, $student ? EntryBasis::StudentGroup : EntryBasis::TeacherGroup, [$student ? 'student' : 'teacher', 'cg-1'], new \DateTimeImmutable('2026-01-01'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
