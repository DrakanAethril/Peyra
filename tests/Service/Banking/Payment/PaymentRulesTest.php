<?php

declare(strict_types=1);

namespace App\Tests\Service\Banking\Payment;

use App\Service\Accounting\Ledger\EntryDraft;
use App\Service\Accounting\Ledger\EntryLineDraft;
use App\Service\Banking\Payment\PaymentEntries;
use App\Service\Banking\Payment\PaymentRules;
use App\Service\Shared\Money;
use PHPUnit\Framework\TestCase;

/**
 * The figures and entries of a payment (step 2.6b), pure, on amounts computed by hand.
 */
final class PaymentRulesTest extends TestCase
{
    public function testTheAmountSpreadsOverTheInvoicesInTheOrderChosen(): void
    {
        // 1 500,00 over 1 200,00 then 900,00: the first whole, 300,00 of the second.
        self::assertSame([120_000, 30_000], self::cents(PaymentRules::allocate(Money::cents(150_000), [Money::cents(120_000), Money::cents(90_000)])));
        // Exactly the two.
        self::assertSame([120_000, 90_000], self::cents(PaymentRules::allocate(Money::cents(210_000), [Money::cents(120_000), Money::cents(90_000)])));
        // More than both: refused.
        self::assertNull(PaymentRules::allocate(Money::cents(210_001), [Money::cents(120_000), Money::cents(90_000)]));
        // Less than the first: the second is named for nothing.
        self::assertSame([50_000, 0], self::cents(PaymentRules::allocate(Money::cents(50_000), [Money::cents(120_000), Money::cents(90_000)])));
    }

    public function testTheVatOnReceiptsFollowsWhatIsReceivedAndCompletesOnTheLastPayment(): void
    {
        // Invoice 1 200,00 TTC, VAT 200,00 pending. 480,00 received: 200 × 480 / 1 200 = 80,00.
        self::assertSame(8_000, PaymentRules::vatTransfer(Money::cents(20_000), Money::cents(120_000), Money::zero(), Money::zero(), Money::cents(48_000))->cents);
        // 333,33 received: 200 × 333,33 / 1 200 = 55,555 → 55,56 (half up).
        self::assertSame(5_556, PaymentRules::vatTransfer(Money::cents(20_000), Money::cents(120_000), Money::zero(), Money::zero(), Money::cents(33_333))->cents);
        // The rest 720,00 after 480,00: 200,00 − 80,00 = 120,00, whatever the proportion says.
        self::assertSame(12_000, PaymentRules::vatTransfer(Money::cents(20_000), Money::cents(120_000), Money::cents(48_000), Money::cents(8_000), Money::cents(72_000))->cents);
        // Three thirds of 100,00 TTC with 16,67 of VAT: 5,56 + 5,56 + the rest 5,55.
        self::assertSame(556, PaymentRules::vatTransfer(Money::cents(1_667), Money::cents(10_000), Money::zero(), Money::zero(), Money::cents(3_333))->cents);
        self::assertSame(555, PaymentRules::vatTransfer(Money::cents(1_667), Money::cents(10_000), Money::cents(6_666), Money::cents(1_112), Money::cents(3_334))->cents);
        // An invoice under VAT on debits has nothing pending.
        self::assertSame(0, PaymentRules::vatTransfer(Money::zero(), Money::cents(120_000), Money::zero(), Money::zero(), Money::cents(48_000))->cents);
    }

    public function testADiscountedBillBringsItsAmountLessTheCharges(): void
    {
        // 5 000,00 − 41,67 − 12,00 − 2,40 = 4 943,93.
        self::assertSame(494_393, PaymentRules::discountNet(Money::cents(500_000), Money::cents(4_167), Money::cents(1_200), Money::cents(240))->cents);
    }

    public function testTheEntriesOfAPayment(): void
    {
        $on = new \DateTimeImmutable('2026-03-10');
        // A cheque received waits on 511200; its VAT on receipts moves to 445710.
        self::assertSame([
            ['511200', '', 72_000, 0], ['411000', 'C0019', 0, 72_000], ['445800', '', 12_000, 0], ['445710', '', 0, 12_000],
        ], self::lines(PaymentEntries::receipt('BQ', PaymentEntries::CHEQUES_TO_CASH, '411000', 'C0019', $on, 'CHQ 1234567', 'Girard CHQ 1234567', Money::cents(72_000), Money::cents(12_000), null)));
        // A bill drawn: 413000 names the customer and carries the due date.
        $bill = PaymentEntries::receipt('OD', PaymentEntries::BILLS_RECEIVABLE, '411000', 'C0019', $on, 'LCR-0001', 'Girard LCR-0001', Money::cents(500_000), Money::zero(), new \DateTimeImmutable('2026-04-30'));
        self::assertSame([['413000', 'C0019', 500_000, 0], ['411000', 'C0019', 0, 500_000]], self::lines($bill));
        self::assertSame('2026-04-30', $bill->lines[0]->dueDate?->format('Y-m-d'));
        // A disbursement by transfer.
        self::assertSame([['401000', 'F0001', 324_840, 0], ['512000', '', 0, 324_840]], self::lines(PaymentEntries::disbursement('BQ', '512000', '401000', 'F0001', $on, 'VIR-0312', 'Scierie VIR-0312', Money::cents(324_840))));
        // Two cheques remitted: the bank debited of the total, 511200 credited one by one.
        self::assertSame([['512000', '', 215_000, 0], ['511200', '', 0, 143_000], ['511200', '', 0, 72_000]], self::lines(PaymentEntries::chequeDeposit('BQ', '512000', $on, 'REM-0008', [['label' => 'Vial', 'amount' => Money::cents(143_000)], ['label' => 'Comptant', 'amount' => Money::cents(72_000)]])));
        // A bill at collection, then one discounted.
        self::assertSame([['511300', '', 500_000, 0], ['413000', 'C0019', 0, 500_000]], self::lines(PaymentEntries::billCollection('BQ', 'C0019', $on, 'LCR-0001', 'x', Money::cents(500_000))));
        self::assertSame([['512000', '', 494_393, 0], ['661600', '', 4_167, 0], ['627500', '', 1_200, 0], ['445660', '', 240, 0], ['413000', 'C0019', 0, 500_000]], self::lines(PaymentEntries::billDiscount('BQ', '512000', 'C0019', $on, 'LCR-0001', 'x', Money::cents(500_000), Money::cents(4_167), Money::cents(1_200), Money::cents(240))));
    }

    /**
     * @param list<Money>|null $amounts
     *
     * @return list<int>|null
     */
    private static function cents(?array $amounts): ?array
    {
        return null === $amounts ? null : array_map(static fn (Money $amount): int => $amount->cents, $amounts);
    }

    /** @return list<array{string, string, int, int}> */
    private static function lines(EntryDraft $draft): array
    {
        return array_map(static fn (EntryLineDraft $line): array => [$line->account, $line->thirdParty, $line->debit->cents, $line->credit->cents], $draft->lines);
    }
}
