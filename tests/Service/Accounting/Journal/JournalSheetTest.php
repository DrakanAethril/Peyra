<?php

declare(strict_types=1);

namespace App\Tests\Service\Accounting\Journal;

use App\Entity\Audit\AuditEvent;
use App\Entity\Identity\User;
use App\Enum\Accounting\JournalType;
use App\Enum\Audit\AuditAction;
use App\Enum\Dossier\DossierKind;
use App\Enum\Identity\AccountKind;
use App\Enum\Identity\EntryBasis;
use App\Repository\Accounting\JournalRepository;
use App\Service\Accounting\Journal\JournalDraft;
use App\Service\Accounting\Journal\JournalSheet;
use App\Service\Dossier\DossierContext;
use App\Tests\Support\SavesDossiers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** A dossier's journals (step 1.2a): code, type, the counterpart of a bank or cash journal, one à-nouveaux. */
final class JournalSheetTest extends KernelTestCase
{
    use SavesDossiers;

    public function testAJournalIsCheckedFieldByField(): void
    {
        [$sheet, $dossier] = $this->setUpDossier();

        $draft = self::draft('b q-1', '', 'cheque', '512100');
        self::assertNull($sheet->check($draft, $dossier));
        self::assertSame([
            'code' => 'Un code de journal compte de 1 à 6 lettres ou chiffres, sans accent (ACH, BQ1).',
            'label' => 'Indiquez l’intitulé du journal.',
            'type' => 'Choisissez le type du journal.',
        ], $draft->errors);

        $draft = self::draft('od', 'Opérations diverses', 'general', '512100');
        self::assertNull($sheet->check($draft, $dossier));
        self::assertSame(['counterpart' => 'Seuls les journaux de banque et de caisse ont un compte de contrepartie.'], $draft->errors);
    }

    public function testABankJournalBalancesAgainstABankAccountOfThePlan(): void
    {
        [$sheet, $dossier] = $this->setUpDossier();

        $missing = self::draft('BQ1', 'Crédit Coopératif', 'bank', '');
        self::assertNull($sheet->check($missing, $dossier));
        self::assertSame('Un journal de banque a son compte de contrepartie (512000).', $missing->errors['counterpart'] ?? null);
        $absent = self::draft('BQ1', 'Crédit Coopératif', 'bank', '512900');
        self::assertNull($sheet->check($absent, $dossier));
        self::assertSame('Le compte 512900 n’est pas dans le plan du dossier.', $absent->errors['counterpart'] ?? null);
        $wrong = self::draft('BQ1', 'Crédit Coopératif', 'bank', '511200');
        self::assertNull($sheet->check($wrong, $dossier));
        self::assertSame('La contrepartie d’un journal de banque est un compte de banque (512000).', $wrong->errors['counterpart'] ?? null);

        $draft = self::draft(' bq1 ', ' Crédit  Coopératif ', 'bank', '512 100');
        $fields = $sheet->check($draft, $dossier);
        self::assertNotNull($fields);
        self::assertSame(['BQ1', 'Crédit Coopératif', JournalType::Bank, '512100'], [$draft->code, $draft->label, $fields->type, $fields->counterpart?->getNumber()]);
    }

    public function testCodesAreUniqueAndADossierHasOneOpeningJournal(): void
    {
        [$sheet, $dossier, $student] = $this->setUpDossier();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $draft = self::draft('AN', 'À-nouveaux', 'opening', '');
        $fields = $sheet->check($draft, $dossier);
        self::assertNotNull($fields);
        $opening = $sheet->create($dossier, $draft, $fields, $student);
        $entityManager->flush();

        $same = self::draft('AN', 'Autre', 'general', '');
        self::assertNull($sheet->check($same, $dossier));
        self::assertSame('Le journal AN existe déjà : À-nouveaux.', $same->errors['code'] ?? null);
        $second = self::draft('AN2', 'À-nouveaux bis', 'opening', '');
        self::assertNull($sheet->check($second, $dossier));
        self::assertSame('Le dossier a déjà son journal d’à-nouveaux : AN.', $second->errors['type'] ?? null);

        $renamed = JournalDraft::of($opening);
        $renamed->label = 'Reports à nouveau';
        $fields = $sheet->check($renamed, $dossier, $opening);
        self::assertNotNull($fields, 'Le journal d’à-nouveaux se modifie lui-même.');
        self::assertTrue($sheet->update($opening, $renamed, $fields, $student));
        self::assertFalse($sheet->update($opening, $renamed, $fields, $student));
        $sheet->delete($opening, $student);
        $entityManager->flush();

        self::assertSame([], self::getContainer()->get(JournalRepository::class)->findByDossier($dossier));
        $actions = array_map(static fn (AuditEvent $e): AuditAction => $e->getAction(), $entityManager->getRepository(AuditEvent::class)->findBy([], ['id' => 'ASC']));
        self::assertSame([AuditAction::JournalCreated, AuditAction::JournalChanged, AuditAction::JournalDeleted], \array_slice($actions, -3));
    }

    /** @return array{JournalSheet, \App\Entity\Dossier\Dossier, User} */
    private function setUpDossier(): array
    {
        self::bootKernel();
        $student = new User('uid:ines', 'ines.morel', 'Inès Morel', AccountKind::Student, EntryBasis::StudentGroup, ['student', 'cg-1'], new \DateTimeImmutable('2026-01-01'));
        self::getContainer()->get(EntityManagerInterface::class)->persist($student);
        $dossier = $this->savedDossier(DossierKind::Student, $student);
        $this->installPlan($dossier);
        self::getContainer()->get(DossierContext::class)->enter($dossier);

        return [self::getContainer()->get(JournalSheet::class), $dossier, $student];
    }

    private static function draft(string $code, string $label, string $type, string $counterpart): JournalDraft
    {
        $draft = new JournalDraft();
        $draft->code = $code;
        $draft->label = $label;
        $draft->type = $type;
        $draft->counterpart = $counterpart;

        return $draft;
    }
}
