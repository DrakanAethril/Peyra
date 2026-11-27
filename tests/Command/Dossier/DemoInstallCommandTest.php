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
use App\Enum\ThirdParty\PaymentMethod;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Repository\Accounting\EntryRepository;
use App\Repository\Dossier\DossierRepository;
use App\Service\Accounting\Ledger\EntryScope;
use App\Service\Accounting\Ledger\LedgerReader;
use App\Service\Dossier\DossierContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** app:demo:install puts Menuiserie Vasseur SARL in the common library once, from fixtures/demo/: exercises, plan, journals, third parties. */
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
        self::assertSame('2026-01-01', $vasseur->getDossierDate()->format('Y-m-d'));
        self::assertSame('99941273600018', $vasseur->getCompany()->getSiret());
        self::assertSame(8_000_000, $vasseur->getCompany()->getCapital()->cents);

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
        $entries = $context->within($vasseur, static fn (): array => self::getContainer()->get(EntryRepository::class)->findAll());
        self::assertCount(1, $entries);
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
            self::assertSame(-2_719_644, $balance('401000'), 'Fournisseurs : 27 196,44, les 10 factures ouvertes.');
            self::assertSame(684_000, $owed('C0031'), 'Bâti-Confort : 6 840,00 en 416000.');
            self::assertSame(-286_200, $owed('C0019'), 'Girard : l’acompte de 2 862,00 reçu en 419100.');
            self::assertSame(-1_500_000, $owed('A0001'), 'Compte courant de Bernard Vasseur, 455100.');
        });
    }
}
