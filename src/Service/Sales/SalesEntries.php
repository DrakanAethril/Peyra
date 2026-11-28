<?php

declare(strict_types=1);

namespace App\Service\Sales;

use App\Enum\Sales\SalesDocumentType;
use App\Service\Accounting\Entry\GridLine;
use App\Service\Document\Processing\PieceParty;
use App\Service\Document\Processing\PieceProposal;
use App\Service\Shared\Money;

/**
 * The entry a validated invoicing document asks for (step 2.3b, pure): what AccountingGateway is
 * handed, in the grid's own shape (PieceProposal). It never reads anything but what it is given.
 *
 * - An invoice: the customer debited with the amount due (the total including tax less the
 *   deposits), each deposit taken back - 419100 debited with its bases, its VAT debited on the
 *   account it was credited to -, the cash discount on 665000, the revenue credited account by
 *   account (an article's revenue account, 706000 for a free line), the VAT of each rate credited.
 * - A deposit invoice: the customer debited with its total, 419100 credited with its bases (the
 *   advance received on the order), its VAT credited.
 * - A credit note: the invoice's entry, sides reversed.
 *
 * The VAT is credited on 445710 « TVA collectée », or on 445800 « Taxes sur le chiffre d'affaires
 * à régulariser ou en attente » when it is due on receipt: the payment moves it to 445710 (step 2.6).
 */
final class SalesEntries
{
    public const string COLLECTED_VAT = '445710';
    public const string VAT_ON_RECEIPTS = '445800';
    public const string CUSTOMER_ADVANCES = '419100';
    public const string CASH_DISCOUNTS = '665000';
    /** A free line's revenue: « Prestations de services ». */
    public const string DEFAULT_REVENUE = '706000';

    /**
     * @param list<array{account: string, amount: Money}> $revenue one per line, the account its article names
     * @param string                                         $customerCode the customer's code, for the 419100 lines
     */
    public static function propose(
        SalesDocumentType $type,
        PieceParty $party,
        string $customerCode,
        string $number,
        \DateTimeImmutable $date,
        ?\DateTimeImmutable $dueDate,
        array $revenue,
        SalesTotals $totals,
        bool $vatOnReceipts,
        string $journal,
    ): PieceProposal {
        if (!$type->isInvoicing()) {
            throw new \LogicException('Only an invoicing document goes to the books.');
        }
        // An invoice puts the customer on the debit; a credit note reverses every side.
        $customerOnDebit = SalesDocumentType::CreditNote !== $type;
        $lines = [];
        $lines[] = self::line($party->accountCell, $party->thirdPartyCell, $totals->amountDue, $customerOnDebit, $dueDate?->format('Y-m-d'));

        if ([] !== $totals->deposits) {
            $lines[] = self::line(self::CUSTOMER_ADVANCES, $customerCode, Money::sum(array_map(static fn (SalesDeposit $d): Money => $d->excludingTax(), $totals->deposits)), $customerOnDebit, null);
            /** @var array<string, Money> $taken */
            $taken = [];
            foreach ($totals->deposits as $deposit) {
                $account = $deposit->vatOnReceipts ? self::VAT_ON_RECEIPTS : self::COLLECTED_VAT;
                $taken[$account] = ($taken[$account] ?? Money::zero())->plus($deposit->vat());
            }
            foreach ($taken as $account => $vat) {
                $lines[] = self::line((string) $account, '', $vat, $customerOnDebit, null);
            }
        }
        $lines[] = self::line(self::CASH_DISCOUNTS, '', $totals->cashDiscount, $customerOnDebit, null);

        if (SalesDocumentType::Deposit === $type) {
            $lines[] = self::line(self::CUSTOMER_ADVANCES, $customerCode, $totals->excludingTax, !$customerOnDebit, null);
        } else {
            /** @var array<string, Money> $byAccount */
            $byAccount = [];
            foreach ($revenue as $row) {
                $byAccount[$row['account']] = ($byAccount[$row['account']] ?? Money::zero())->plus($row['amount']);
            }
            foreach ($byAccount as $account => $amount) {
                $lines[] = self::line((string) $account, '', $amount, !$customerOnDebit, null);
            }
        }
        foreach ($totals->vatLines as $vat) {
            $lines[] = self::line($vatOnReceipts ? self::VAT_ON_RECEIPTS : self::COLLECTED_VAT, '', $vat->vat, !$customerOnDebit, null);
        }

        return new PieceProposal(
            $journal,
            $date->format('Y-m-d'),
            $number,
            mb_substr(trim($party->name.' '.$number), 0, 250),
            array_values(array_filter($lines)),
        );
    }

    /** A line on the debit or the credit; none for a zero amount. */
    private static function line(string $account, string $thirdParty, Money $amount, bool $debit, ?string $due): ?GridLine
    {
        if ($amount->isZero()) {
            return null;
        }
        if ($amount->isNegative()) {
            $debit = !$debit;
            $amount = $amount->abs();
        }

        return new GridLine($account, $thirdParty, '', $debit ? $amount->cents : 0, $debit ? 0 : $amount->cents, $due);
    }
}
