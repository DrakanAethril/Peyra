<?php

declare(strict_types=1);

namespace App\Service\Banking\Payment;

use App\Service\Accounting\Ledger\EntryDraft;
use App\Service\Accounting\Ledger\EntryLineDraft;
use App\Service\Sales\SalesEntries;
use App\Service\Shared\Money;

/**
 * The entries a payment asks the books for (step 2.6b), pure - the accounts a French chart gives
 * these operations (PCG 2026), each a line a module proposes and AccountingGateway hands to the
 * Ledger:
 *
 * - a receipt: the bank or cash account (511200 for a cheque until its remittance, 413000 for a
 *   bill drawn) debited, the customer credited; with VAT on receipts, 445800 → 445710;
 * - a disbursement: the supplier debited, the bank or cash account credited;
 * - a remittance of cheques: the bank debited of the total, 511200 credited cheque by cheque;
 * - a bill remitted for collection: 511300 debited, 413000 credited; the bank's credit at its due
 *   date is a line of the statement, posted from the reconciliation;
 * - a bill remitted for discount: the bank debited of what it credits, the interest (661600), the
 *   commission (627500) and its VAT (445660) charged, 413000 credited.
 */
final class PaymentEntries
{
    public const string CHEQUES_TO_CASH = '511200';
    public const string BILLS_AT_COLLECTION = '511300';
    public const string BILLS_RECEIVABLE = '413000';
    public const string DISCOUNT_INTEREST = '661600';
    public const string BILL_FEES = '627500';
    public const string DEDUCTIBLE_VAT = '445660';

    /** The party's side of a payment: its account and its code (« 411000 », « C0019 »). */
    public static function receipt(string $journal, string $debitAccount, string $partyAccount, string $partyCode, \DateTimeImmutable $on, string $reference, string $label, Money $amount, Money $vatOnReceipts, ?\DateTimeImmutable $billDue): EntryDraft
    {
        $lines = [
            EntryLineDraft::debit($debitAccount, $amount, '', self::BILLS_RECEIVABLE === $debitAccount ? $partyCode : '', $billDue),
            EntryLineDraft::credit($partyAccount, $amount, '', $partyCode),
        ];
        if ($vatOnReceipts->isPositive()) {
            $lines[] = EntryLineDraft::debit(SalesEntries::VAT_ON_RECEIPTS, $vatOnReceipts, 'TVA sur les encaissements');
            $lines[] = EntryLineDraft::credit(SalesEntries::COLLECTED_VAT, $vatOnReceipts, 'TVA sur les encaissements');
        }

        return new EntryDraft($journal, $on, $reference, $label, $lines);
    }

    public static function disbursement(string $journal, string $creditAccount, string $partyAccount, string $partyCode, \DateTimeImmutable $on, string $reference, string $label, Money $amount): EntryDraft
    {
        return new EntryDraft($journal, $on, $reference, $label, [
            EntryLineDraft::debit($partyAccount, $amount, '', $partyCode),
            EntryLineDraft::credit($creditAccount, $amount),
        ]);
    }

    /** @param list<array{label: string, amount: Money}> $cheques */
    public static function chequeDeposit(string $journal, string $bankAccount, \DateTimeImmutable $on, string $reference, array $cheques): EntryDraft
    {
        $lines = [EntryLineDraft::debit($bankAccount, Money::sum(array_map(static fn (array $cheque): Money => $cheque['amount'], $cheques)))];
        foreach ($cheques as $cheque) {
            $lines[] = EntryLineDraft::credit(self::CHEQUES_TO_CASH, $cheque['amount'], $cheque['label']);
        }

        return new EntryDraft($journal, $on, $reference, 'Remise de chèques '.$reference, $lines);
    }

    public static function billCollection(string $journal, string $partyCode, \DateTimeImmutable $on, string $reference, string $label, Money $amount): EntryDraft
    {
        return new EntryDraft($journal, $on, $reference, $label, [
            EntryLineDraft::debit(self::BILLS_AT_COLLECTION, $amount),
            EntryLineDraft::credit(self::BILLS_RECEIVABLE, $amount, '', $partyCode),
        ]);
    }

    public static function billDiscount(string $journal, string $bankAccount, string $partyCode, \DateTimeImmutable $on, string $reference, string $label, Money $amount, Money $interest, Money $commission, Money $commissionVat): EntryDraft
    {
        $lines = [EntryLineDraft::debit($bankAccount, PaymentRules::discountNet($amount, $interest, $commission, $commissionVat))];
        foreach ([[self::DISCOUNT_INTEREST, $interest, 'Intérêts d’escompte'], [self::BILL_FEES, $commission, 'Commission d’escompte'], [self::DEDUCTIBLE_VAT, $commissionVat, 'TVA sur commission']] as [$account, $charge, $chargeLabel]) {
            if ($charge->isPositive()) {
                $lines[] = EntryLineDraft::debit($account, $charge, $chargeLabel);
            }
        }
        $lines[] = EntryLineDraft::credit(self::BILLS_RECEIVABLE, $amount, '', $partyCode);

        return new EntryDraft($journal, $on, $reference, $label, $lines);
    }
}
