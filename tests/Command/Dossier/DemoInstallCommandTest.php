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
use App\Enum\Shared\BtsProcess;
use App\Enum\ThirdParty\PaymentMethod;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Repository\Accounting\EntryLineRepository;
use App\Repository\Accounting\EntryRepository;
use App\Repository\Accounting\EntrySubscriptionRepository;
use App\Repository\Accounting\EntryTemplateRepository;
use App\Repository\Dossier\DossierRepository;
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

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Menuiserie Vasseur SARL installé dans la bibliothèque commune', $tester->getDisplay());
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('déjà installé', $tester->getDisplay());

        $templates = self::getContainer()->get(DossierRepository::class)->findCommonTemplates();
        self::assertCount(1, $templates);
        $vasseur = $templates[0];
        self::assertSame(DossierKind::Template, $vasseur->getKind());
        self::assertNull($vasseur->getOwner());
        self::assertSame('2026-01-31', $vasseur->getDossierDate()->format('Y-m-d'), 'La fin de janvier, dont les achats et la banque sont saisis.');
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
        $entries = $context->within($vasseur, static fn (): array => self::getContainer()->get(EntryRepository::class)->findBy([], ['id' => 'ASC']));
        self::assertCount(49, $entries, 'Les à-nouveaux, 16 achats et 32 mouvements de la Banque Populaire de janvier.');
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

        // January's purchases and Banque Populaire: validated by date, the last two of each left in the brouillard.
        $states = [];
        foreach ($entries as $entry) {
            $key = $entry->getJournal()->getCode().' '.($entry->isValidated() ? 'validée' : 'brouillard');
            $states[$key] = ($states[$key] ?? 0) + 1;
        }
        self::assertSame(['AN validée' => 1, 'ACH validée' => 14, 'BQ2 validée' => 30, 'ACH brouillard' => 2, 'BQ2 brouillard' => 2], $states, 'États par journal.');
        $purchase = array_values(array_filter($entries, static fn ($entry): bool => 'FA-2601-0012' === $entry->getDocument()))[0];
        self::assertSame(['601000', '624100', '445660', '401000'], array_map(static fn ($line): string => $line->getAccount()->getNumber(), $purchase->getLines()));
        self::assertSame([415_812, '2026-02-07'], [$purchase->totalCredit()->cents, $purchase->getLines()[3]->getDueDate()?->format('Y-m-d')]);
        $end = new \DateTimeImmutable('2026-01-31');
        $context->within($vasseur, static function () use ($reader, $account, $end): void {
            $bank = $reader->accountBalance($account('512200') ?? throw new \LogicException(), new \DateTimeImmutable('2026-01-01'), $end, EntryScope::WithDrafts)->balance()->cents;
            self::assertSame(1_511_678, $bank, 'Banque Populaire au 31/01/2026 : 15 116,78 D (operations-2026.md § 2.5).');
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

        // Its subscriptions (step 1.4c): the rent and the leasing from February, into the brouillard;
        // none is due on 31/01/2026, the first falls on 01/02.
        $subscriptions = $context->within($vasseur, static fn (): array => self::getContainer()->get(EntrySubscriptionRepository::class)->findByDossier($vasseur));
        self::assertSame(
            ['Crédit-bail du chariot élévateur CBAIL 41200 5 2026-02 31 0 draft', 'Loyer de l\'atelier LOYER 320000 1 2026-02 11 0 draft'],
            array_map(static fn ($s): string => implode(' ', [$s->getLabel(), $s->getTemplate()->getCode(), $s->getAmount()?->cents, $s->getDayOfMonth(), $s->getFirstMonth()->format('Y-m'), $s->getOccurrences(), $s->getGenerated(), $s->getTarget()->value]), $subscriptions),
        );
        self::assertSame('2026-02-01', SubscriptionSchedule::next($subscriptions[1]->getFirstMonth(), 1, 11, 0)?->format('Y-m-d'));

        // A lettering under way (letterings.yaml): the Scierie's two December invoices and their
        // transfers (A 7 878,00, B 4 704,00), SCI Vasseur's rent and Loire Bail's lease (A each);
        // January's invoice of the Scierie, 4 158,12, is left open, and the other suppliers.
        $context->within($vasseur, static function (): void {
            $board = self::getContainer()->get(LetteringBoard::class);
            $lettered = [];
            foreach (self::getContainer()->get(EntryLineRepository::class)->findBy(['letter' => ['A', 'B']]) as $line) {
                $lettered[] = $line->getThirdParty()?->getCode().' '.$line->getLetter().' '.($line->getDebit()->cents ?: -$line->getCredit()->cents).' '.$line->getLetteredOn()?->format('Y-m-d');
            }
            sort($lettered);
            self::assertSame([
                'F0001 A -787800 2026-01-31', 'F0001 A 787800 2026-01-31', 'F0001 B -470400 2026-01-31', 'F0001 B 470400 2026-01-31',
                'F0009 A -384000 2026-01-31', 'F0009 A 384000 2026-01-31', 'F0018 A -49440 2026-01-31', 'F0018 A 49440 2026-01-31',
            ], $lettered);
            $scierie = array_values(array_filter($board->rows(), static fn ($row): bool => '401SCIE' === $row->key->code()));
            self::assertSame([1, -415_812], [$scierie[0]->openLines, $scierie[0]->openBalance()->cents]);
        });

        // Up to 31/03, February and March of both: four drafts, LOY-2026-02 = 3 840,00, LBE-2026-03 = 494,40.
        $outcome = $context->within($vasseur, static fn () => self::getContainer()->get(SubscriptionRun::class)->generate($vasseur, new \DateTimeImmutable('2026-03-31'), 'test', new \DateTimeImmutable('2026-11-28 10:00')));
        self::assertSame([4, 0, []], [$outcome->drafts, $outcome->pending, $outcome->faults]);
        $context->within($vasseur, static function () use ($vasseur): void {
            $entries = self::getContainer()->get(EntryRepository::class);
            self::assertSame(384_000, $entries->findOneBy(['dossier' => $vasseur, 'document' => 'LOY-2026-02'])?->totalDebit()->cents);
            self::assertSame(49_440, $entries->findOneBy(['dossier' => $vasseur, 'document' => 'LBE-2026-03'])?->totalDebit()->cents);
            self::assertSame('2026-03-05', $entries->findOneBy(['dossier' => $vasseur, 'document' => 'LBE-2026-03'])?->getEntryDate()->format('Y-m-d'));
        });

        // Its payroll settings (step 4.1, payroll.yaml): the establishment, four fictitious bodies,
        // the standard plan paid to them and its own health insurance, 28,00 € on each side.
        $context->within($vasseur, static function () use ($vasseur): void {
            $establishment = self::getContainer()->get(PayrollEstablishmentRepository::class)->findOfDossier($vasseur);
            self::assertSame(['8.69', '2.1', null, '151.67'], [$establishment?->getHeadcount(), $establishment?->getWorkAccidentRate(), $establishment?->getMobilityRate(), $establishment?->getMonthlyHours()]);
            $bodies = self::getContainer()->get(PayrollBodyRepository::class)->findByDossier($vasseur);
            self::assertSame(['Urssaf Rhône-Alpes (simulée)', 'Caisse de retraite Loire-Forez (fictive)', 'Prévoyance Forez Mutuelle (fictive)', 'Mutuelle des Monts du Forez (fictive)'], array_map(static fn ($body): string => $body->getName(), $bodies));
            $items = self::getContainer()->get(PayrollItemRepository::class);
            self::assertCount(49, $items->findPlan($vasseur));
            self::assertSame('Urssaf Rhône-Alpes (simulée)', $items->findOneByCode($vasseur, '2200')?->getBody()?->getName());
            $health = $items->findOneByCode($vasseur, '2010');
            self::assertSame([2800, 2800, 'Mutuelle des Monts du Forez (fictive)'], [$health?->getEmployeeRate()->getAmount()?->cents, $health?->getEmployerRate()->getAmount()?->cents, $health?->getBody()?->getName()]);
        });
    }
}
