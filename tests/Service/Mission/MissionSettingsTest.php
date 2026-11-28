<?php

declare(strict_types=1);

namespace App\Tests\Service\Mission;

use App\Entity\Document\Document;
use App\Entity\Dossier\Distribution;
use App\Entity\Dossier\DistributionCopy;
use App\Entity\Identity\SchoolClass;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Enum\Accounting\AccountingBehaviour;
use App\Enum\Accounting\AccountingModule;
use App\Enum\Accounting\AssistanceLevel;
use App\Enum\Document\DocumentType;
use App\Enum\Dossier\DistributionMode;
use App\Enum\Dossier\DossierKind;
use App\Enum\Identity\AccountKind;
use App\Enum\Identity\ClassLevel;
use App\Enum\Identity\EntryBasis;
use App\Enum\Mission\MissionModule;
use App\Repository\Document\DocumentRepository;
use App\Service\Accounting\Entry\AssistanceLevels;
use App\Service\Accounting\Gateway\AccountingBehaviours;
use App\Service\Document\Documents;
use App\Service\Dossier\DossierContext;
use App\Service\Mission\CurrentMission;
use App\Service\Mission\MissionAssignments;
use App\Service\Mission\MissionRefused;
use App\Service\Mission\MissionSheet;
use App\Tests\Support\SavesDossiers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A mission's settings applied to the dossiers it touches (step 2.9a): a template, a distribution of it
 * to BTS CG 1 with Manon's copy (date 15/03/2026), another template's distribution. Before the mission,
 * « Proposer », « Avertissements », every module; given, the mission's behaviour, level, modules and
 * guided entry - Manon's own level winning -; the latest mission given takes over. A template's pièce
 * dated after the copy's date arrives in it only once that date has come.
 */
final class MissionSettingsTest extends KernelTestCase
{
    use SavesDossiers;

    public function testAMissionGivenSetsTheDossiersOfItsDistribution(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $at = new \DateTimeImmutable('2026-11-28 10:00');
        $teacher = $this->account(AccountKind::Teacher, 'durand');
        $manon = $this->account(AccountKind::Student, 'manon');
        $year = new SchoolYear(new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2027-08-31'), $at);
        $class = new SchoolClass($year, 'BTS CG 1', ClassLevel::First, $at);
        $entityManager->persist($year);
        $entityManager->persist($class);
        $template = $this->savedDossier(DossierKind::Template, $teacher);
        $other = $this->savedDossier(DossierKind::Template, $teacher, null, 'Autre modèle');
        $copy = $this->savedDossier(DossierKind::Student, $manon);
        $distribution = new Distribution($template, $teacher, $class, DistributionMode::Individual, new \DateTimeImmutable('2026-03-15'), $at);
        $otherDistribution = new Distribution($other, $teacher, $class, DistributionMode::Individual, new \DateTimeImmutable('2026-03-15'), $at);
        $line = DistributionCopy::forStudent($distribution, $manon);
        $line->markReady($copy, $at);
        foreach ([$distribution, $otherDistribution, $line] as $one) {
            $entityManager->persist($one);
        }
        $entityManager->flush();

        $behaviours = $container->get(AccountingBehaviours::class);
        $levels = $container->get(AssistanceLevels::class);
        $current = $container->get(CurrentMission::class);
        self::assertSame(AccountingBehaviour::Propose, $behaviours->for($copy, AccountingModule::Documents));
        self::assertSame(AssistanceLevel::Warnings, $levels->for($copy));
        self::assertTrue($current->opens($copy, MissionModule::Payroll));

        $sheet = $container->get(MissionSheet::class);
        $mission = $sheet->create($template, 'Janvier, de la pièce à l’écriture', $teacher, $at);
        self::assertSame([AssistanceLevel::Warnings, AccountingBehaviour::LetEnter, MissionModule::cases()], [$mission->getAssistanceLevel(), $mission->getBehaviour(), $mission->getModules()]);
        $sheet->describe($mission, 'Janvier, de la pièce à l’écriture', 'Les pièces de janvier arrivent.', ['documents', 'accounting', 'sales'], $teacher);
        $sheet->settle($mission, AssistanceLevel::Guided, AccountingBehaviour::Post, true, $teacher);
        $sheet->setTasks($mission, [['title' => 'Contrôler', 'instruction' => ''], ['title' => 'Comptabiliser', 'instruction' => 'Par la pièce.']], $teacher);
        $sheet->aimAt($mission, ['A1.5', 'A1.2', 'A1.2'], $teacher);
        self::assertSame(['A1.2', 'A1.5'], $mission->getActivities());

        $giving = $container->get(MissionAssignments::class);
        foreach ([[$otherDistribution, $at->modify('+1 week')], [$distribution, $at->modify('-1 hour')], [null, null]] as [$to, $due]) {
            try {
                $giving->give($mission, $to, $due, $teacher, $at);
                self::fail('Another template\'s distribution, a past due date, nothing chosen: refused.');
            } catch (MissionRefused $refused) {
                self::assertNotSame([], $refused->errors);
            }
        }
        $giving->give($mission, $distribution, $at->modify('+1 week'), $teacher, $at);

        self::assertSame($mission, $current->of($copy));
        self::assertNull($current->of($template), 'A template plays no mission.');
        self::assertSame(AccountingBehaviour::Post, $behaviours->for($copy, AccountingModule::Sales));
        self::assertSame(AssistanceLevel::Guided, $levels->for($copy));
        self::assertTrue($current->opens($copy, MissionModule::Sales));
        self::assertFalse($current->opens($copy, MissionModule::Payroll));
        self::assertTrue($current->opensGuidedEntry($copy));
        $manon->setAssistanceLevel(AssistanceLevel::None);
        self::assertSame(AssistanceLevel::None, $levels->for($copy), 'The student\'s own level wins.');
        $manon->setAssistanceLevel(null);

        try {
            $giving->give($mission, $distribution, $at->modify('+1 week'), $teacher, $at);
            self::fail('Given once to a distribution.');
        } catch (MissionRefused $refused) {
            self::assertSame(['distribution'], array_keys($refused->errors));
        }
        // The next mission takes over.
        $next = $sheet->duplicate($mission, $teacher, $at->modify('+1 day'));
        $sheet->settle($next, AssistanceLevel::Blocking, AccountingBehaviour::Propose, false, $teacher);
        $giving->give($next, $distribution, $at->modify('+2 weeks'), $teacher, $at->modify('+1 day'));
        self::assertSame($next, $current->of($copy));
        self::assertSame(AccountingBehaviour::Propose, $behaviours->for($copy, AccountingModule::Sales));
        self::assertSame('Janvier, de la pièce à l’écriture (copie)', $next->getTitle());

        // A pièce of the copy arriving on 20/03 is not there on 15/03; the template's is.
        $context = $container->get(DossierContext::class);
        $documents = $container->get(DocumentRepository::class);
        $context->across([$template, $copy], function () use ($entityManager, $template, $copy, $teacher, $container): void {
            $early = new Document($template, DocumentType::PurchaseInvoice, 'dossiers/x/pieces/a.pdf', 'a.pdf', 'application/pdf', new \DateTimeImmutable('2026-03-15'), null);
            $entityManager->persist($early);
            $entityManager->persist(new Document($copy, DocumentType::PurchaseInvoice, 'dossiers/x/pieces/b.pdf', 'b.pdf', 'application/pdf', new \DateTimeImmutable('2026-03-20'), null));
            $entityManager->persist(new Document($copy, DocumentType::PurchaseInvoice, 'dossiers/x/pieces/c.pdf', 'c.pdf', 'application/pdf', new \DateTimeImmutable('2026-03-15'), null));
            $entityManager->flush();
            $container->get(Documents::class)->scheduleArrival($early, new \DateTimeImmutable('2026-04-02'), $teacher);
            $entityManager->flush();
        });
        $context->within($copy, function () use ($documents, $copy): void {
            self::assertSame(['c.pdf'], array_map(static fn (Document $one): string => $one->getFileName(), $documents->findByDossier($copy)));
            self::assertSame(1, $documents->countToProcess($copy));
        });
        $context->within($template, function () use ($documents, $template): void {
            self::assertSame(['2026-04-02'], array_map(static fn (Document $one): string => $one->getReceivedOn()->format('Y-m-d'), $documents->findByDossier($template)));
        });
    }

    private function account(AccountKind $kind, string $login): User
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $student = AccountKind::Student === $kind;
        $user = new User('uid:'.$login, $login, ucfirst($login), $kind, $student ? EntryBasis::StudentGroup : EntryBasis::TeacherGroup, [$student ? 'student' : 'teacher', 'cg-1'], new \DateTimeImmutable('2026-01-01'));
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }
}
