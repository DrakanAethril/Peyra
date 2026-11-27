<?php

declare(strict_types=1);

namespace App\Tests\Service\Accounting\Ledger;

use App\Entity\Dossier\Dossier;
use App\Repository\Accounting\AccountRepository;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Service\Accounting\Ledger\EntryScope;
use App\Service\Accounting\Ledger\LedgerMovement;
use App\Service\Accounting\Ledger\LedgerReader;
use App\Service\Accounting\Ledger\Totals;
use App\Service\Accounting\Ledger\TrialBalanceRow;
use App\Service\Dossier\Exercises;
use App\Tests\Support\BuildsLedger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Balances are a reading of the lines (conception § 5). Four entries, every figure below worked
 * out by hand:
 *
 * - AN 01/01, validated: 512000 D 10 000,00 · 411000 C0019 D 1 200,00 · 401000 F0001 C 2 400,00 ·
 *   101300 C 8 800,00 (11 200,00 each side);
 * - ACH 08/01, validated: 607000 D 3 465,10 · 445660 D 693,02 · 401000 F0001 C 4 158,12;
 * - BQ 20/01, brouillard: 401000 F0001 D 2 400,00 · 512000 C 2 400,00;
 * - VTE 05/02, validated: 411000 C0019 D 1 440,00 · 701000 C 1 200,00 · 445710 C 240,00.
 */
final class LedgerReaderTest extends KernelTestCase
{
    use BuildsLedger;

    public function testAnAccountsBalanceIsReadWithOrWithoutTheBrouillard(): void
    {
        $dossier = $this->books();
        $reader = self::getContainer()->get(LedgerReader::class);
        $bank = $this->account($dossier, '512000');

        // 10 000,00 - 2 400,00 = 7 600,00 with the brouillard; 10 000,00 without.
        self::assertSame([1_000_000, 240_000, 760_000], self::figures($reader->accountBalance($bank, null, self::day('2026-01-31'), EntryScope::WithDrafts)));
        self::assertSame([1_000_000, 0, 1_000_000], self::figures($reader->accountBalance($bank, null, self::day('2026-01-31'), EntryScope::Validated)));
        // Before the payment: the opening only.
        self::assertSame([1_000_000, 0, 1_000_000], self::figures($reader->accountBalance($bank, null, self::day('2026-01-19'), EntryScope::WithDrafts)));
        self::assertTrue($reader->accountBalance($this->account($dossier, '606100'), null, self::day('2026-12-31'), EntryScope::WithDrafts)->isZero());
    }

    public function testAThirdPartysOutstandingAmountIsTheSumOfTheLinesThatNameIt(): void
    {
        $dossier = $this->books();
        $reader = self::getContainer()->get(LedgerReader::class);
        $thirdParties = self::getContainer()->get(ThirdPartyRepository::class);
        $scierie = $thirdParties->findOneByCode($dossier, 'F0001');
        $girard = $thirdParties->findOneByCode($dossier, 'C0019');
        self::assertNotNull($scierie);
        self::assertNotNull($girard);

        // Scierie: 2 400,00 + 4 158,12 = 6 558,12 owed, 2 400,00 paid in the brouillard: 4 158,12 C.
        self::assertSame([240_000, 655_812, -415_812], self::figures($reader->thirdPartyBalance($scierie, null, self::day('2026-01-31'), EntryScope::WithDrafts)));
        self::assertSame([0, 655_812, -655_812], self::figures($reader->thirdPartyBalance($scierie, null, self::day('2026-01-31'), EntryScope::Validated)));
        // Girard: 1 200,00 at the opening, 1 440,00 more in February.
        self::assertSame(120_000, $reader->thirdPartyBalance($girard, null, self::day('2026-01-31'), EntryScope::WithDrafts)->balance()->cents);
        self::assertSame(264_000, $reader->thirdPartyBalance($girard, null, self::day('2026-02-28'), EntryScope::WithDrafts)->balance()->cents);

        $all = $reader->thirdPartyTotals($dossier, null, self::day('2026-02-28'), EntryScope::Validated);
        self::assertSame([(int) $scierie->getId() => -655_812, (int) $girard->getId() => 264_000], array_map(static fn (Totals $t): int => $t->balance()->cents, $all));
    }

    public function testTheTrialBalanceListsTheAccountsThatMovedInNumberOrder(): void
    {
        $dossier = $this->books();
        $rows = self::getContainer()->get(LedgerReader::class)->trialBalance($dossier, self::day('2026-01-01'), self::day('2026-01-31'), EntryScope::Validated);

        self::assertSame([
            ['101300', 0, 880_000],
            ['401000', 0, 655_812],
            ['411000', 120_000, 0],
            ['445660', 69_302, 0],
            ['512000', 1_000_000, 0],
            ['607000', 346_510, 0],
        ], array_map(static fn (TrialBalanceRow $r): array => [$r->account->getNumber(), $r->totals->debit->cents, $r->totals->credit->cents], $rows));
        // 1 200,00 + 693,02 + 10 000,00 + 3 465,10 = 15 358,12 = 8 800,00 + 6 558,12.
        $total = array_reduce($rows, static fn (Totals $carry, TrialBalanceRow $r): Totals => $carry->plus($r->totals), Totals::zero());
        self::assertSame([1_535_812, 1_535_812], [$total->debit->cents, $total->credit->cents]);
    }

    public function testAnAccountsMovementsCarryTheirRunningBalance(): void
    {
        $dossier = $this->books();
        $movements = self::getContainer()->get(LedgerReader::class)->movements($this->account($dossier, '401000'), self::day('2026-01-01'), self::day('2026-01-31'), EntryScope::WithDrafts);

        // - 2 400,00 ; - 6 558,12 ; - 4 158,12.
        self::assertSame([
            ['2026-01-01', 'AN', 0, 240_000, -240_000],
            ['2026-01-08', 'ACH', 0, 415_812, -655_812],
            ['2026-01-20', 'BQ', 240_000, 0, -415_812],
        ], array_map(static fn (LedgerMovement $m): array => [$m->line->getEntry()->getEntryDate()->format('Y-m-d'), $m->line->getEntry()->getJournal()->getCode(), $m->line->getDebit()->cents, $m->line->getCredit()->cents, $m->balanceAfter->cents], $movements));
    }

    public function testTheGeneralLedgerStartsFromWhatTheExerciseCarriedBefore(): void
    {
        $dossier = $this->books();
        $accounts = self::getContainer()->get(LedgerReader::class)->generalLedger($dossier, self::day('2026-02-01'), self::day('2026-02-28'), EntryScope::Validated);
        $byNumber = [];
        foreach ($accounts as $account) {
            $byNumber['n'.$account->account->getNumber()] = $account;
        }

        self::assertSame(['n101300', 'n401000', 'n411000', 'n445660', 'n445710', 'n512000', 'n607000', 'n701000'], array_keys($byNumber));
        $customers = $byNumber['n411000'];
        // 1 200,00 before February, 1 440,00 in it: 2 640,00.
        self::assertSame([120_000, 264_000], [$customers->before->balance()->cents, $customers->total->balance()->cents]);
        self::assertSame([264_000], array_map(static fn (LedgerMovement $m): int => $m->balanceAfter->cents, $customers->movements));
        self::assertSame([[], 1_000_000], [$byNumber['n512000']->movements, $byNumber['n512000']->total->balance()->cents], 'Aucun mouvement en février : le compte garde son solde.');
        self::assertSame(-120_000, $byNumber['n701000']->total->balance()->cents);
    }

    public function testAReadingStaysInsideTheWindowItIsGiven(): void
    {
        $dossier = $this->books();
        self::getContainer()->get(Exercises::class)->open($dossier, '2025', self::day('2025-01-01'), self::day('2025-12-31'));
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->entry($dossier, 'BQ', '2025-12-20', 'OLD', ['512000 D 500.00', '101300 C 500.00'], true);
        $reader = self::getContainer()->get(LedgerReader::class);
        $bank = $this->account($dossier, '512000');

        self::assertSame('2026-01-01', $reader->exerciseStart($dossier, self::day('2026-03-15'))?->format('Y-m-d'));
        self::assertNull($reader->exerciseStart($dossier, self::day('2024-06-01')));
        self::assertSame(1_000_000, $reader->accountBalance($bank, self::day('2026-01-01'), self::day('2026-01-31'), EntryScope::Validated)->balance()->cents, 'L’exercice 2026 s’ouvre sur ses à-nouveaux.');
        self::assertSame(1_050_000, $reader->accountBalance($bank, null, self::day('2026-01-31'), EntryScope::Validated)->balance()->cents);
    }

    private function books(): Dossier
    {
        $dossier = $this->ledgerDossier();
        $this->entry($dossier, 'AN', '2026-01-01', 'AN-2026-001', ['512000 D 10000.00', '411000/C0019 D 1200.00', '401000/F0001 C 2400.00', '101300 C 8800.00'], true);
        $this->entry($dossier, 'ACH', '2026-01-08', 'FA-2601-0012', ['607000 D 3465.10', '445660 D 693.02', '401000/F0001 C 4158.12'], true);
        $this->entry($dossier, 'BQ', '2026-01-20', 'VIR-SCIE', ['401000/F0001 D 2400.00', '512000 C 2400.00']);
        $this->entry($dossier, 'VTE', '2026-02-05', 'FV-2026-0001', ['411000/C0019 D 1440.00', '701000 C 1200.00', '445710 C 240.00'], true);

        return $dossier;
    }

    private function account(Dossier $dossier, string $number): \App\Entity\Accounting\Account
    {
        return self::getContainer()->get(AccountRepository::class)->findOneByNumber($dossier, $number) ?? throw new \LogicException($number);
    }

    /** @return array{int, int, int} debit, credit, balance in cents */
    private static function figures(Totals $totals): array
    {
        return [$totals->debit->cents, $totals->credit->cents, $totals->balance()->cents];
    }

    private static function day(string $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day);
    }
}
