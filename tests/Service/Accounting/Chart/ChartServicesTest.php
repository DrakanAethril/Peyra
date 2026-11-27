<?php

declare(strict_types=1);

namespace App\Tests\Service\Accounting\Chart;

use App\Entity\Accounting\Account;
use App\Entity\Accounting\Journal;
use App\Entity\Audit\AuditEvent;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Accounting\AccountOrigin;
use App\Enum\Accounting\JournalType;
use App\Enum\Audit\AuditAction;
use App\Enum\Dossier\DossierKind;
use App\Enum\Identity\AccountKind;
use App\Enum\Identity\EntryBasis;
use App\Repository\Accounting\AccountRepository;
use App\Service\Accounting\Chart\AccountDraft;
use App\Service\Accounting\Chart\AccountSheet;
use App\Service\Accounting\Chart\ChartImport;
use App\Service\Accounting\Chart\ChartImportLine;
use App\Service\Accounting\Chart\ChartInstaller;
use App\Service\Accounting\Chart\ReferenceCharts;
use App\Service\Dossier\DossierContext;
use App\Tests\Support\SavesDossiers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A dossier's plan of accounts (step 1.2a): the copy of the reference chart, the account fiche
 * (AccountSheet) and the CSV import (ChartImport), each gesture in the audit trail.
 */
final class ChartServicesTest extends KernelTestCase
{
    use SavesDossiers;

    public function testTheReferenceChartIsCopiedOnceAtSixDigitsWithItsDefaults(): void
    {
        [$dossier] = $this->dossier();
        $plan = $this->accounts()->findPlan($dossier);

        self::assertSame('pcg-2026', $dossier->getChartCode());
        self::assertCount(\count(self::getContainer()->get(ReferenceCharts::class)->get('pcg-2026')?->copiedAccounts() ?? []), $plan);
        $suppliers = $this->accounts()->findOneByNumber($dossier, '401000');
        self::assertNotNull($suppliers);
        self::assertSame(['Fournisseurs', true, true, AccountOrigin::Reference], [$suppliers->getLabel(), $suppliers->isLettrable(), $suppliers->isCollective(), $suppliers->getOrigin()]);
        $purchases = $this->accounts()->findOneByNumber($dossier, '601000');
        self::assertSame([false, false], [$purchases?->isLettrable(), $purchases?->isCollective()]);
        self::assertSame('Emprunts participatifs', $this->accounts()->findOneByNumber($dossier, '168200')?->getLabel());
        self::assertNotNull($this->audit(AuditAction::ChartInstalled));

        $this->expectException(\LogicException::class);
        self::getContainer()->get(ChartInstaller::class)->install($dossier, self::getContainer()->get(ReferenceCharts::class)->all()[0], 'test');
    }

    public function testAnAccountIsCheckedFieldByFieldAndANumberOutsideThePlanIsOnlySignalled(): void
    {
        [$dossier, $student] = $this->dossier();
        $sheet = self::getContainer()->get(AccountSheet::class);

        $draft = new AccountDraft();
        $draft->number = '601000';
        $draft->collective = true;
        self::assertFalse($sheet->check($draft, $dossier));
        self::assertSame([
            'number' => 'Le compte 601000 existe déjà : Achats stockés - Matières premières et fournitures.',
            'label' => 'Indiquez l’intitulé du compte.',
            'collective' => 'Seul un compte de tiers (classe 4) est collectif.',
        ], $draft->errors);

        $draft = self::typed(' 601 300 ', '  Achats   de vitrages ');
        self::assertTrue($sheet->check($draft, $dossier));
        self::assertSame(['601300', 'Achats de vitrages', null], [$draft->number, $draft->label, $draft->warning]);
        $account = $sheet->create($dossier, $draft, $student);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        self::assertSame(AccountOrigin::Created, $account->getOrigin());
        self::assertSame('Compte 601300 Achats de vitrages créé dans Menuiserie Vasseur SARL', $this->audit(AuditAction::AccountCreated)?->getSummary());

        $outside = new AccountDraft();
        $outside->number = '190000';
        $outside->label = 'Compte inventé';
        self::assertTrue($sheet->check($outside, $dossier));
        self::assertSame('Le compte 190000 ne subdivise aucun compte du PCG 2026 : vérifiez son numéro.', $outside->warning);
    }

    public function testAnAccountChangesAndLeavesThePlanUnlessAJournalNeedsIt(): void
    {
        [$dossier, $student] = $this->dossier();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $sheet = self::getContainer()->get(AccountSheet::class);
        $bank = $this->accounts()->findOneByNumber($dossier, '512100');
        self::assertNotNull($bank);
        $entityManager->persist(new Journal($dossier, 'BQ1', 'Crédit Coopératif', JournalType::Bank, $bank));
        $entityManager->flush();

        $draft = AccountDraft::of($bank);
        self::assertFalse($sheet->update($bank, $draft, $student), 'Rien n’a changé : rien n’est écrit.');
        $draft->number = '601900';
        self::assertFalse($sheet->check($draft, $dossier, $bank));
        self::assertSame('Ce compte est la contrepartie du journal BQ1 : il reste un compte de banque.', $draft->errors['number'] ?? null);
        self::assertSame('Ce compte est la contrepartie du journal BQ1 : changez d’abord la contrepartie du journal.', $sheet->deletionRefusal($bank));

        $draft = AccountDraft::of($bank);
        $draft->number = '512110';
        $draft->label = 'Crédit Coopératif';
        self::assertTrue($sheet->check($draft, $dossier, $bank));
        self::assertTrue($sheet->update($bank, $draft, $student));
        $entityManager->flush();
        self::assertSame('Compte 512100 renuméroté 512110', $this->audit(AuditAction::AccountChanged)?->getSummary());

        $unused = $this->accounts()->findOneByNumber($dossier, '601000');
        self::assertNotNull($unused);
        self::assertNull($sheet->deletionRefusal($unused));
        $sheet->delete($unused, $student);
        $entityManager->flush();
        self::assertNull($this->accounts()->findOneByNumber($dossier, '601000'));
        self::assertNotNull($this->audit(AuditAction::AccountDeleted));
    }

    public function testAnImportWithOneRefusedLineWritesNothing(): void
    {
        [$dossier, $student] = $this->dossier();
        $import = self::getContainer()->get(ChartImport::class);
        $before = \count($this->accounts()->findPlan($dossier));

        $report = $import->prepare($dossier, "numero;intitule;collectif\n601300;Achats de vitrages;\n601300;Achats de verre;\n606110;Carburants;oui\n", false);

        self::assertFalse($report->isApplicable());
        self::assertSame([ChartImportLine::CREATE, ChartImportLine::REFUSED, ChartImportLine::REFUSED], array_map(static fn (ChartImportLine $l): string => $l->action, $report->lines));
        self::assertSame(['Le compte 601300 figure déjà ligne 2.', 'Seul un compte de tiers (classe 4) est collectif.'], array_map(static fn (ChartImportLine $l): ?string => $l->message, $report->refusedLines()));
        self::assertCount($before, $this->accounts()->findPlan($dossier));
        self::assertSame('Ce fichier ne contient aucun compte.', $import->prepare($dossier, "\n\n", false)->fileRefusal);
    }

    public function testAnAcceptedImportAddsTheMissingAccountsAndUpdatesOnlyWhenAsked(): void
    {
        [$dossier, $student] = $this->dossier();
        $import = self::getContainer()->get(ChartImport::class);
        $file = "numero;intitule;lettrable;collectif\n601300;Achats de vitrages;non;non\n512100;Crédit Coopératif;;\n401000;Fournisseurs;oui;oui\n190000;Compte inventé;;\n";

        $kept = $import->prepare($dossier, $file, false);
        self::assertSame([ChartImportLine::CREATE, ChartImportLine::SAME, ChartImportLine::SAME, ChartImportLine::CREATE], array_map(static fn (ChartImportLine $l): string => $l->action, $kept->lines));
        self::assertSame(['Le compte 190000 ne subdivise aucun compte du PCG 2026 : vérifiez son numéro.'], array_map(static fn (ChartImportLine $l): ?string => $l->message, $kept->notedLines()));

        $report = $import->prepare($dossier, $file, true);
        self::assertSame([ChartImportLine::CREATE, ChartImportLine::UPDATE, ChartImportLine::SAME, ChartImportLine::CREATE], array_map(static fn (ChartImportLine $l): string => $l->action, $report->lines));
        $import->apply($dossier, $report, $student, 'plan.csv');
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        self::assertSame(AccountOrigin::Imported, $this->accounts()->findOneByNumber($dossier, '601300')?->getOrigin());
        self::assertSame('Crédit Coopératif', $this->accounts()->findOneByNumber($dossier, '512100')?->getLabel());
        self::assertSame('Plan de comptes importé de plan.csv : 2 créés, 1 modifiés', $this->audit(AuditAction::ChartImported)?->getSummary());
    }

    /** @return array{Dossier, User} a student's dossier with the PCG 2026, in its context */
    private function dossier(): array
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $student = new User('uid:ines', 'ines.morel', 'Inès Morel', AccountKind::Student, EntryBasis::StudentGroup, ['student', 'cg-1'], new \DateTimeImmutable('2026-01-01'));
        $entityManager->persist($student);
        $dossier = $this->savedDossier(DossierKind::Student, $student);
        $this->installPlan($dossier);
        self::getContainer()->get(DossierContext::class)->enter($dossier);

        return [$dossier, $student];
    }

    private static function typed(string $number, string $label): AccountDraft
    {
        $draft = new AccountDraft();
        $draft->number = $number;
        $draft->label = $label;

        return $draft;
    }

    private function accounts(): AccountRepository
    {
        return self::getContainer()->get(AccountRepository::class);
    }

    private function audit(AuditAction $action): ?AuditEvent
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getRepository(AuditEvent::class)->findOneBy(['action' => $action], ['id' => 'DESC']);
    }
}
