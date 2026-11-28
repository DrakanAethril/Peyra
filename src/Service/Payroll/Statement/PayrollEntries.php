<?php

declare(strict_types=1);

namespace App\Service\Payroll\Statement;

use App\Enum\Payroll\PayrollItemKind;
use App\Service\Accounting\Entry\GridLine;
use App\Service\Document\Processing\PieceProposal;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;

/**
 * The entries the payroll asks for (step 4.5), pure - what AccountingGateway is handed, in the grid's
 * own shape (PieceProposal), from the month's validated payslips (PayrollMonthStatements) and nothing else:
 *
 * - **the month's entry**, an « opération diverse » dated the payslips' day: each element of gross pay
 *   and item not subject charged on its account, each employer's share charged on its account (the
 *   general reduction lessening it), account by account; then each body's account credited with both
 *   shares of its contributions, each deduction's account with what it took off the net (the tax on
 *   442100, an advance on 425000…), and 421000 with the net paid - which balances it, since the net is
 *   the gross less the employee's contributions, plus what is not subject, less the deductions;
 * - **a payment** (the salaries, a body's contributions): the account it settles debited, the bank's
 *   credited, at the dossier's date.
 *
 * A negative amount goes on the other side; a zero amount makes no line.
 */
final class PayrollEntries
{
    /** The month's entry: its pièce « PAIE-2026-03 ». */
    public static function monthDocument(\DateTimeImmutable $month): string
    {
        return 'PAIE-'.$month->format('Y-m');
    }

    public static function month(PayrollMonthStatements $statements, string $journal): PieceProposal
    {
        /** @var array<string, Money> $debits */
        $debits = [];
        /** @var array<string, Money> $credits */
        $credits = [];
        $net = Money::zero();
        foreach ($statements->allocations as $allocation) {
            switch ($allocation->kind) {
                case PayrollItemKind::Gross:
                case PayrollItemKind::NotSubject:
                    $debits[$allocation->account] = ($debits[$allocation->account] ?? Money::zero())->plus($allocation->employee);
                    $net = $net->plus($allocation->employee);
                    break;
                case PayrollItemKind::Contribution:
                    $debits[$allocation->account] = ($debits[$allocation->account] ?? Money::zero())->plus($allocation->employer);
                    $credits[$allocation->owed] = ($credits[$allocation->owed] ?? Money::zero())->plus($allocation->employee)->plus($allocation->employer);
                    $net = $net->minus($allocation->employee);
                    break;
                case PayrollItemKind::Deduction:
                    // A deduction's amount is negative on the payslip: what it took off the net is credited.
                    $credits[$allocation->account] = ($credits[$allocation->account] ?? Money::zero())->minus($allocation->employee);
                    $net = $net->plus($allocation->employee);
                    break;
            }
        }
        ksort($debits, \SORT_STRING);
        ksort($credits, \SORT_STRING);
        $lines = [];
        foreach ($debits as $account => $amount) {
            $lines[] = self::line((string) $account, $amount, true);
        }
        foreach ($credits as $account => $amount) {
            $lines[] = self::line((string) $account, $amount, false);
        }
        $lines[] = self::line(PayrollAccounts::NET_PAY, $net, false);
        $month = $statements->month;

        return new PieceProposal($journal, $month->modify('last day of this month')->format('Y-m-d'), self::monthDocument($month), 'Paie '.FrenchFormat::ofPeriod($month), array_values(array_filter($lines)));
    }

    /** A payment: the account it settles debited, the bank's credited, in the bank's journal at that day. */
    public static function payment(string $account, Money $amount, string $journal, string $bankAccount, \DateTimeImmutable $on, string $document, string $label): PieceProposal
    {
        return new PieceProposal($journal, $on->format('Y-m-d'), $document, $label, array_values(array_filter([
            self::line($account, $amount, true),
            self::line($bankAccount, $amount, false),
        ])));
    }

    private static function line(string $account, Money $amount, bool $debit): ?GridLine
    {
        if ($amount->isZero()) {
            return null;
        }
        if ($amount->isNegative()) {
            [$debit, $amount] = [!$debit, $amount->abs()];
        }

        return new GridLine($account, '', '', $debit ? $amount->cents : 0, $debit ? 0 : $amount->cents, null);
    }
}
