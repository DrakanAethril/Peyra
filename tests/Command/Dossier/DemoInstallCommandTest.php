<?php

declare(strict_types=1);

namespace App\Tests\Command\Dossier;

use App\Entity\Accounting\Account;
use App\Entity\Accounting\Journal;
use App\Entity\Dossier\Exercise;
use App\Entity\Dossier\Period;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Accounting\AccountOrigin;
use App\Enum\Accounting\EntryOrigin;
use App\Enum\Dossier\DossierKind;
use App\Enum\Dossier\TemplateLevel;
use App\Enum\Payroll\ContractType;
use App\Enum\Payroll\EmployeeState;
use App\Enum\Payroll\FormalityState;
use App\Enum\Payroll\WorkingTime;
use App\Enum\Shared\BtsProcess;
use App\Enum\ThirdParty\PaymentMethod;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Repository\Accounting\EntryLineRepository;
use App\Repository\Accounting\EntryRepository;
use App\Repository\Accounting\EntrySubscriptionRepository;
use App\Repository\Accounting\EntryTemplateRepository;
use App\Repository\Dossier\DossierRepository;
use App\Repository\Payroll\EmployeeRepository;
use App\Repository\Payroll\PayrollBodyRepository;
use App\Repository\Payroll\PayrollEstablishmentRepository;
use App\Repository\Payroll\PayrollItemRepository;
use App\Service\Accounting\Ledger\EntryScope;
use App\Service\Accounting\Ledger\LedgerReader;
use App\Service\Accounting\Lettering\LetteringBoard;
use App\Service\Accounting\Subscription\SubscriptionRun;
use App\Service\Accounting\Subscription\SubscriptionSchedule;
use App\Service\Accounting\Template\TemplateCall;
use App\Service\Dossier\DossierContext;
use App\Service\Payroll\Hiring\HiringFormalities;
use App\Service\Regulatory\RegulatorySync;
use App\Service\Shared\Money;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** app:demo:install puts Menuiserie Vasseur SARL in the common library once, from fixtures/demo/: exercises, plan, journals, third parties, entries, entry templates, subscriptions. */
final class DemoInstallCommandTest extends KernelTestCase
{
    public function testInstallsTheTemplateOnceWithItsExercises(): void
    {
        $tester = new CommandTester(new Application(self::bootKernel())->find('app:demo:install'));
        // As production syncs the millésimes before installing it: the DPAE reads the regulatory delays (step 4.2b).
        self::getContainer()->get(RegulatorySync::class)->run(new \DateTimeImmutable('2026-11-28 10:00'));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Menuiserie Vasseur SARL installé dans la bibliothèque commune', $tester->getDisplay());
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('déjà installé', $tester->getDisplay());

        $templates = self::getContainer()->get(DossierRepository::class)->findCommonTemplates();
        self::assertCount(1, $templates);
        $vasseur = $templates[0];
        self::assertSame(DossierKind::Template, $vasseur->getKind());
        self::assertNull($vasseur->getOwner());
        self::assertSame('2026-03-31', $vasseur->getDossierDate()->format('Y-m-d'), 'La fin du premier trimestre, dont les écritures sont saisies.');
        self::assertSame('99941273600018', $vasseur->getCompany()->getSiret());
        self::assertSame(8_000_000, $vasseur->getCompany()->getCapital()->cents);
        self::assertSame('Vasseur, à-nouveaux 2026', $vasseur->getLibraryTitle(), 'Its card in the library.');
        self::assertSame(TemplateLevel::First, $vasseur->getLibraryLevel());
        self::assertSame([BtsProcess::P1, BtsProcess::P2], $vasseur->getLibraryProcesses());

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        [$exercises, $closedPeriods] = self::getContainer()->get(DossierContext::class)->within($vasseur, static fn (): array => [
            $entityManager->getRepository(Exercise::class)->findBy([], ['startsOn' => 'ASC']),
            $entityManager->getRepository(Period::class)->count(['state' => 'closed']),
        ]);
        self::assertSame(['2025', '2026'], array_map(static fn (Exercise $exercise): string => $exercise->getCode(), $exercises));
        self::assertSame([true, false], array_map(static fn (Exercise $exercise): bool => $exercise->isClosed(), $exercises));
        self::assertSame(12, $closedPeriods);

        // Its plan: the PCG 2026 (the exercise it starts in opens in 2026) and its own accounts, its six journals.
        self::assertSame('pcg-2026', $vasseur->getChartCode());
        [$accounts, $journals] = self::getContainer()->get(DossierContext::class)->within($vasseur, static fn (): array => [
            $entityManager->getRepository(Account::class)->findBy([], ['number' => 'ASC']),
            $entityManager->getRepository(Journal::class)->findBy([], ['code' => 'ASC']),
        ]);
        $account = static function (string $number) use ($accounts): ?Account {
            foreach ($accounts as $account) {
                if ($account->getNumber() === $number) {
                    return $account;
                }
            }

            return null;
        };
        $bank = $account('512100');
        self::assertNotNull($bank);
        self::assertSame('Crédit Coopératif', $bank->getLabel());
        self::assertSame(AccountOrigin::Reference, $bank->getOrigin(), 'Le compte du plan garde son origine sous son nouvel intitulé.');
        self::assertSame(AccountOrigin::Created, $account('606110')?->getOrigin());
        self::assertSame('Emprunt Banque Populaire 2023 (fourgon, plaqueuse)', $account('164100')?->getLabel());
        self::assertTrue($account('401000')?->isCollective());
        self::assertSame(
            ['ACH Achats', 'AN À-nouveaux', 'BQ1 Crédit Coopératif 512100', 'BQ2 Banque Populaire 512200', 'OD Opérations diverses', 'VTE Ventes'],
            array_map(static fn (Journal $j): string => trim($j->getCode().' '.$j->getLabel().' '.$j->getCounterpartAccount()?->getNumber()), $journals),
        );

        // Its 59 third parties (donnees/demo/tiers.yaml): customers and suppliers on their collectives.
        $thirdParties = self::getContainer()->get(DossierContext::class)->within($vasseur, static fn (): array => $entityManager->getRepository(ThirdParty::class)->findBy([], ['code' => 'ASC']));
        self::assertCount(59, $thirdParties);
        $byKind = array_count_values(array_map(static fn (ThirdParty $t): string => $t->getKind()->value, $thirdParties));
        self::assertSame(['other' => 12, 'customer' => 26, 'supplier' => 21], $byKind, 'Codes A, C, D, E, F, O dans l’ordre.');
        $byCode = [];
        foreach ($thirdParties as $thirdParty) {
            $byCode[$thirdParty->getCode()] = $thirdParty;
        }
        $scierie = $byCode['F0001'];
        self::assertSame(['Scierie du Haut-Forez', '401000', '401SCIE', '512904337', 'FR41512904337', '30 jours date de facture'], [$scierie->getName(), $scierie->getAccount()?->getNumber(), $scierie->getAuxiliaryCode(), $scierie->getSiren(), $scierie->getVatNumber(), $scierie->getPaymentTerms()->label()]);
        self::assertSame(['404000', '404MBIN'], [$byCode['F0019']->getAccount()?->getNumber(), $byCode['F0019']->getAuxiliaryCode()]);
        self::assertSame('Fin de mois + 45 jours', $byCode['C0017']->getPaymentTerms()->label());
        self::assertSame([null, null, PaymentMethod::Card], [$byCode['F0021']->getAccount(), $byCode['F0021']->getAuxiliaryCode(), $byCode['F0021']->getPaymentMethod()], 'Station U : aucun compte, le ticket se saisit en banque.');
        self::assertSame(['431000', null, ThirdPartyKind::Other], [$byCode['O0001']->getAccount()?->getNumber(), $byCode['O0001']->getAuxiliaryCode(), $byCode['O0001']->getKind()]);
        self::assertSame('164100', $byCode['E0001']->getAccount()?->getNumber());
        self::assertTrue($byCode['C0019']->isIndividual());
        self::assertSame(['DE', 'DE999999999'], [$byCode['F0006']->getCountry(), $byCode['F0006']->getVatNumber()]);

        // Its opening balance: one validated entry of AN, balanced at 459 260,31 (ouverture-2026.yaml).
        $context = self::getContainer()->get(DossierContext::class);
        // The install clears the entity manager as it goes: what follows reads lazily, inside the dossier.
        $context->enter($vasseur);
        $entries = $context->within($vasseur, static fn (): array => self::getContainer()->get(EntryRepository::class)->findBy([], ['id' => 'ASC']));
        self::assertCount(245, $entries, 'Les à-nouveaux, les 80 écritures de janvier, 82 de février, 80 de mars et les deux abonnements de mars.');
        $opening = $entries[0];
        self::assertSame(['AN-2026-00001', 'AN-2026-001', '2026-01-01', EntryOrigin::Opening, 67], [$opening->getNumberLabel(), $opening->getDocument(), $opening->getEntryDate()->format('Y-m-d'), $opening->getOrigin(), \count($opening->getLines())]);
        self::assertSame([45_926_031, 45_926_031], [$opening->totalDebit()->cents, $opening->totalCredit()->cents]);

        // Its balances, read at the 01/01/2026 against the prepared data's controls.
        $reader = self::getContainer()->get(LedgerReader::class);
        $day = new \DateTimeImmutable('2026-01-01');
        $balance = static fn (string $number): int => $reader->accountBalance($account($number) ?? throw new \LogicException($number), $day, $day, EntryScope::Validated)->balance()->cents;
        $owed = static fn (string $code): int => $reader->thirdPartyBalance($byCode[$code], $day, $day, EntryScope::Validated)->balance()->cents;
        $context->within($vasseur, static function () use ($balance, $owed): void {
            self::assertSame(821_645, $balance('512100'), 'Crédit Coopératif, relevé n° 12.');
            self::assertSame(10_531_800, $balance('411000'), 'Clients : 105 318,00, les 13 factures ouvertes.');
            self::assertSame(-2_719_644 - 384_000, $balance('401000'), 'Fournisseurs : 27 196,44, les 10 factures ouvertes, plus le loyer de janvier (3 840,00) saisi le 01/01.');
            self::assertSame(684_000, $owed('C0031'), 'Bâti-Confort : 6 840,00 en 416000.');
            self::assertSame(-286_200, $owed('C0019'), 'Girard : l’acompte de 2 862,00 reçu en 419100.');
            self::assertSame(-1_500_000, $owed('A0001'), 'Compte courant de Bernard Vasseur, 455100.');
        });

        // The quarter: validated by date, but for the last entries of March and the two drafts the
        // subscriptions generate; February's VAT liquidated, March's left to do.
        $states = [];
        foreach ($entries as $entry) {
            $key = $entry->getJournal()->getCode().' '.($entry->isValidated() ? 'validée' : 'brouillard');
            $states[$key] = ($states[$key] ?? 0) + 1;
        }
        ksort($states);
        self::assertSame([
            'ACH brouillard' => 4, 'ACH validée' => 47, 'AN validée' => 1, 'BQ1 validée' => 31, 'BQ2 brouillard' => 2, 'BQ2 validée' => 103,
            'OD validée' => 10, 'VTE brouillard' => 2, 'VTE validée' => 45,
        ], $states, 'États par journal.');
        $drafts = array_map(static fn ($entry): string => $entry->getDocument(), array_values(array_filter($entries, static fn ($entry): bool => !$entry->isValidated())));
        sort($drafts);
        self::assertSame(['BFM-26-0412', 'ECH-EMP1-33', 'FRAIS-BQ2-03', 'FV-2026-0045', 'FV-2026-0046', 'LBE-2026-03', 'LOY-2026-03', 'PRL-26-00702'], $drafts);
        $purchase = array_values(array_filter($entries, static fn ($entry): bool => 'FA-2601-0012' === $entry->getDocument()))[0];
        self::assertSame(['601000', '624100', '445660', '401000'], array_map(static fn ($line): string => $line->getAccount()->getNumber(), $purchase->getLines()));
        self::assertSame([415_812, '2026-02-07'], [$purchase->totalCredit()->cents, $purchase->getLines()[3]->getDueDate()?->format('Y-m-d')]);
        $context->within($vasseur, static function () use ($reader, $account): void {
            $at = static fn (string $number, string $day): int => $reader->accountBalance($account($number) ?? throw new \LogicException($number), new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable($day), EntryScope::Validated)->balance()->cents;
            // operations-2026.md § 2.5: January's balances.
            self::assertSame(
                [3_109_029, 1_511_678, 11_272_990, 684_000, -1_414_080, -874_856, -183_878, -88_370, -888_379, 0, 0, 0],
                array_map(static fn (string $number): int => $at($number, '2026-01-31'), ['512100', '512200', '411000', '416000', '401000', '431000', '437100', '442100', '445510', '445710', '445660', '421000']),
                'Soldes au 31/01/2026 (operations-2026.md § 2.5).',
            );
            $revenue = 0;
            foreach (['701000', '704000', '708500', '708800', '709100'] as $number) {
                $revenue -= $at($number, '2026-01-31');
            }
            self::assertSame(7_840_592, $revenue, 'Chiffre d’affaires de janvier : 78 405,92 HT.');
            // § 4: the Crédit Coopératif at the end of February and of March.
            self::assertSame([1_388_389, 2_760_029], [$at('512100', '2026-02-28'), $at('512100', '2026-03-31')], 'Crédit Coopératif : 13 883,89 puis 27 600,29.');
            // The machining centre's deposit (05/02) is imputed on its invoice of 16/03: 103 800,00 - 25 200,00 left to pay on 15/04.
            self::assertSame([2_520_000, 0, -7_860_000], [$at('409100', '2026-02-28'), $at('409100', '2026-03-31'), $at('404000', '2026-03-31')]);
        });

        // Its entry templates (step 1.4b): the rent, the leasing, the fees, a purchase and a sale at 20 %, the bank's.
        $templates = $context->within($vasseur, static fn (): array => self::getContainer()->get(EntryTemplateRepository::class)->findByDossier($vasseur));
        self::assertSame(['ACHAT20 ACH', 'CBAIL ACH', 'FRAISBQ2 BQ2', 'HONOR ACH', 'LOYER ACH', 'REGLFOUR BQ2', 'VENTE20 VTE'], array_map(static fn ($t): string => $t->getCode().' '.$t->getJournal()->getCode(), $templates));
        $rent = $templates[4];
        $call = $context->within($vasseur, static fn (): array => TemplateCall::lines($rent, new \DateTimeImmutable('2026-02-01'), Money::cents(320_000), '', '', $account));
        self::assertSame(
            [['613200', 320_000, 0], ['445660', 64_000, 0], ['401SCIV', 0, 384_000]],
            array_map(static fn ($line): array => [$line->account, $line->debit, $line->credit], $call['lines']),
            'Le loyer de février : 3 200,00 HT, 640,00 de TVA, 3 840,00 à la SCI (LOY-2026-02).',
        );

        // Its subscriptions (step 1.4c): the rent and the leasing from March, into the brouillard;
        // March's are generated at install (the dossier's date is 31/03), April's come next.
        $subscriptions = $context->within($vasseur, static fn (): array => self::getContainer()->get(EntrySubscriptionRepository::class)->findByDossier($vasseur));
        self::assertSame(
            ['Crédit-bail du chariot élévateur CBAIL 41200 5 2026-03 30 1 draft', 'Loyer de l\'atelier LOYER 320000 1 2026-03 10 1 draft'],
            array_map(static fn ($s): string => implode(' ', [$s->getLabel(), $s->getTemplate()->getCode(), $s->getAmount()?->cents, $s->getDayOfMonth(), $s->getFirstMonth()->format('Y-m'), $s->getOccurrences(), $s->getGenerated(), $s->getTarget()->value]), $subscriptions),
        );
        self::assertSame('2026-04-01', SubscriptionSchedule::next($subscriptions[1]->getFirstMonth(), 1, 10, 1)?->format('Y-m-d'));

        // A lettering under way (letterings.yaml), by amount: the Scierie's two December invoices and
        // January's and February's with their transfers (A 7 878,00, B 4 704,00, C 4 158,12, D
        // 6 394,80, E 5 691,60), SCI Vasseur's rent and Loire Bail's lease of January and February;
        // the Scierie's three March invoices are left open, and March's direct debits of the rent and
        // the lease (their invoices are drafts), and the other suppliers.
        $context->within($vasseur, static function (): void {
            $board = self::getContainer()->get(LetteringBoard::class);
            $lettered = [];
            foreach (self::getContainer()->get(EntryLineRepository::class)->findBy(['letter' => ['A', 'B', 'C', 'D', 'E']]) as $line) {
                $lettered[] = $line->getThirdParty()?->getCode().' '.$line->getLetter().' '.($line->getDebit()->cents ?: -$line->getCredit()->cents).' '.$line->getLetteredOn()?->format('Y-m-d');
            }
            sort($lettered);
            self::assertSame([
                'F0001 A -787800 2026-03-31', 'F0001 A 787800 2026-03-31', 'F0001 B -470400 2026-03-31', 'F0001 B 470400 2026-03-31',
                'F0001 C -415812 2026-03-31', 'F0001 C 415812 2026-03-31', 'F0001 D -639480 2026-03-31', 'F0001 D 639480 2026-03-31',
                'F0001 E -569160 2026-03-31', 'F0001 E 569160 2026-03-31',
                'F0009 A -384000 2026-03-31', 'F0009 A 384000 2026-03-31', 'F0009 B -384000 2026-03-31', 'F0009 B 384000 2026-03-31',
                'F0018 A -49440 2026-03-31', 'F0018 A 49440 2026-03-31', 'F0018 B -49440 2026-03-31', 'F0018 B 49440 2026-03-31',
            ], $lettered);
            $open = [];
            foreach ($board->rows() as $row) {
                if (\in_array($row->key->code(), ['401SCIE', '401SCIV', '401LBAI'], true)) {
                    $open[$row->key->code()] = [$row->openLines, $row->openBalance()->cents];
                }
            }
            ksort($open);
            // March: Scierie 8 104,80 + 5 398,80 + 6 345,60; the direct debits of the rent and the lease.
            self::assertSame(['401LBAI' => [1, 49_440], '401SCIE' => [3, -1_984_920], '401SCIV' => [1, 384_000]], $open);
        });

        // Up to 30/04, April of both: two drafts, LOY-2026-04 = 3 840,00, LBE-2026-04 = 494,40.
        $outcome = $context->within($vasseur, static fn () => self::getContainer()->get(SubscriptionRun::class)->generate($vasseur, new \DateTimeImmutable('2026-04-30'), 'test', new \DateTimeImmutable('2026-11-28 10:00')));
        self::assertSame([2, 0, []], [$outcome->drafts, $outcome->pending, $outcome->faults]);
        $context->within($vasseur, static function () use ($vasseur): void {
            $entries = self::getContainer()->get(EntryRepository::class);
            self::assertSame(384_000, $entries->findOneBy(['dossier' => $vasseur, 'document' => 'LOY-2026-04'])?->totalDebit()->cents);
            self::assertSame(49_440, $entries->findOneBy(['dossier' => $vasseur, 'document' => 'LBE-2026-04'])?->totalDebit()->cents);
            self::assertSame('2026-04-05', $entries->findOneBy(['dossier' => $vasseur, 'document' => 'LBE-2026-04'])?->getEntryDate()->format('Y-m-d'));
        });

        // Its payroll settings (step 4.1, payroll.yaml): the establishment, four fictitious bodies,
        // the standard plan paid to them and its own health insurance, 28,00 € on each side.
        $context->within($vasseur, static function () use ($vasseur): void {
            $establishment = self::getContainer()->get(PayrollEstablishmentRepository::class)->findOfDossier($vasseur);
            self::assertSame(['8.69', '2.1', null, '151.67'], [$establishment?->getHeadcount(), $establishment?->getWorkAccidentRate(), $establishment?->getMobilityRate(), $establishment?->getMonthlyHours()]);
            $bodies = self::getContainer()->get(PayrollBodyRepository::class)->findByDossier($vasseur);
            self::assertSame(['Urssaf Rhône-Alpes (simulée)', 'Caisse de retraite Loire-Forez (fictive)', 'Prévoyance Forez Mutuelle (fictive)', 'Mutuelle des Monts du Forez (fictive)'], array_map(static fn ($body): string => $body->getName(), $bodies));
            $items = self::getContainer()->get(PayrollItemRepository::class);
            self::assertCount(59, $items->findPlan($vasseur));
            self::assertSame('Urssaf Rhône-Alpes (simulée)', $items->findOneByCode($vasseur, '2200')?->getBody()?->getName());
            $health = $items->findOneByCode($vasseur, '2010');
            self::assertSame([2800, 2800, 'Mutuelle des Monts du Forez (fictive)'], [$health?->getEmployeeRate()->getAmount()?->cents, $health?->getEmployerRate()->getAmount()?->cents, $health?->getBody()?->getName()]);
        });

        // Its employees (step 4.2a, employees.yaml): S001 to S011, Yanis Chabert's CDD to come on
        // 01/04; Hugo Marchand's apprenticeship at 27 % then 43 % of the minimum wage, Julien Roche's
        // raise from 01/04, Sandrine Gauthier's part time.
        $context->within($vasseur, static function () use ($vasseur): void {
            $employees = self::getContainer()->get(EmployeeRepository::class)->findByDossier($vasseur);
            self::assertSame(['S001', 'S002', 'S003', 'S004', 'S005', 'S006', 'S007', 'S008', 'S009', 'S010', 'S011'], array_map(static fn ($e): string => $e->getRegistrationNumber(), $employees));
            $today = $vasseur->getDossierDate();
            self::assertCount(10, array_filter($employees, static fn ($e): bool => EmployeeState::Present === $e->stateAt($today)));
            [$paul, , $thomas, $hugo, $julien, , $sandrine, , , , $yanis] = $employees;
            self::assertTrue($paul->termsAt($today)->isExecutive());
            self::assertSame([240_000, '151.67'], [$thomas->termsAt($today)->getBaseSalary()->cents, $thomas->termsAt($today)->getMonthlyHours()]);
            self::assertSame([48_649, 49_222, 78_390], array_map(static fn ($t): int => $t->getBaseSalary()->cents, $hugo->getTerms()));
            self::assertSame(ContractType::Apprenticeship, $hugo->getContractType());
            self::assertNull($hugo->getWithholdingRate());
            self::assertSame([225_000, 238_000], [$julien->termsAt($today)->getBaseSalary()->cents, $julien->termsAt(new \DateTimeImmutable('2026-04-01'))->getBaseSalary()->cents]);
            self::assertSame([WorkingTime::PartTime, '104', 147_680], [$sandrine->termsAt($today)->getWorkingTime(), $sandrine->termsAt($today)->getMonthlyHours(), $sandrine->termsAt($today)->getBaseSalary()->cents]);
            self::assertSame([ContractType::FixedTerm, '2026-09-30', EmployeeState::Upcoming], [$yanis->getContractType(), $yanis->getContractEndsOn()?->format('Y-m-d'), $yanis->stateAt($today)]);

            // His formalities (step 4.2b, hiring.yaml): the DPAE of 30/03 and the Urssaf affiliation
            // with it; the visit and the three other affiliations left to do. The older hires are
            // before the dossier.
            $formalities = self::getContainer()->get(HiringFormalities::class);
            self::assertSame(['DPAE-2026-000001', '2026-03-30', false], [$formalities->declaration($yanis)?->getNumber(), $formalities->declaration($yanis)?->getDeclaredOn()->format('Y-m-d'), $formalities->declaration($yanis)?->isLate()]);
            $list = $formalities->checklist($yanis, $today);
            self::assertSame([FormalityState::Done, FormalityState::ToDo, '2026-07-01', 3], [$list->dpae, $list->visit, $list->visitDeadline->format('Y-m-d'), $list->affiliationsMissing]);
            self::assertFalse($formalities->checklist($paul, $today)->followed);
        });
    }
}
