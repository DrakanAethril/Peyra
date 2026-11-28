<?php

declare(strict_types=1);

namespace App\Tests\Service\Banking\Payment;

use App\Entity\Accounting\Entry;
use App\Entity\Accounting\EntryLine;
use App\Enum\Accounting\AccountingModule;
use App\Enum\Banking\BillRemittance;
use App\Enum\Banking\PaymentDirection;
use App\Enum\Banking\PaymentState;
use App\Enum\ThirdParty\PaymentMethod;
use App\Repository\Accounting\JournalRepository;
use App\Service\Accounting\Ledger\Ledger;
use App\Service\Banking\BankingRefused;
use App\Service\Banking\Payment\PaymentRequest;
use App\Service\Banking\Payment\Payments;
use App\Service\Banking\Statement\BankStatements;
use App\Service\Banking\Statement\StatementGenerator;
use App\Service\Shared\Money;
use App\Tests\Support\BuildsLedger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The payments' door (step 2.6b) in BuildsLedger's dossier (BQ on 512000, OD, date 15/03/2026,
 * « Proposer »: each entry goes to the brouillard through the gateway, module Banque): an invoice
 * under VAT on receipts paid in two parts - the VAT moved in proportion, the rest on the last -, the
 * cheque remitted, the two payments lettered with it once validated; a bill drawn, remitted for
 * discount; a supplier paid then the payment deleted with its draft; the statement generator
 * writing what the books hold, less what the teacher leaves out, plus the bank's own lines.
 */
final class PaymentsTest extends KernelTestCase
{
    use BuildsLedger;

    public function testAnInvoicePaidInTwoPartsMovesItsVatAndIsLettered(): void
    {
        $dossier = $this->ledgerDossier();
        $payments = self::getContainer()->get(Payments::class);
        $ledger = self::getContainer()->get(Ledger::class);
        // 1 000,00 HT + 200,00 of VAT on receipts.
        $invoice = $this->entry($dossier, 'VTE', '2026-03-02', 'FV-2026-0101', ['411000/C0019 D 1200.00', '706000 C 1000.00', '445800 C 200.00'], true);
        $party = $payments->parties($dossier, PaymentDirection::Receipt)[0];
        self::assertSame('C0019', $party->getCode());
        $open = $payments->openInvoices($party, PaymentDirection::Receipt);
        self::assertSame([[(int) $invoice->getId(), 120_000, 120_000, 20_000]], array_map(static fn ($one): array => [(int) $one->entry->getId(), $one->total->cents, $one->remaining()->cents, $one->pendingVat->cents], $open));

        // 480,00 by transfer: 200 × 480 / 1 200 = 80,00 of VAT becomes due.
        $transfer = $payments->record($dossier, new PaymentRequest(PaymentDirection::Receipt, PaymentMethod::Transfer, 'C0019', 'BQ', new \DateTimeImmutable('2026-03-05'), 'VIR GIRARD', Money::cents(48_000), null, [(int) $invoice->getId()]), 'test');
        $entry = $transfer->getEntry() ?? throw new \LogicException('Proposed to the brouillard.');
        self::assertSame(['BQ', 'VIR GIRARD', AccountingModule::Banking->value, false], [$entry->getJournal()->getCode(), $entry->getDocument(), $entry->getSource(), $entry->isValidated()]);
        self::assertSame([['512000', 48_000, 0], ['411000', 0, 48_000], ['445800', 8_000, 0], ['445710', 0, 8_000]], self::lines($entry));
        self::assertSame(PaymentState::Recorded, $transfer->state());

        // More than what remains is refused, and writes nothing.
        try {
            $payments->record($dossier, new PaymentRequest(PaymentDirection::Receipt, PaymentMethod::Cheque, 'C0019', 'BQ', new \DateTimeImmutable('2026-03-06'), '1234567', Money::cents(72_001), null, [(int) $invoice->getId()]), 'test');
            self::fail('A payment above what remains due is refused.');
        } catch (BankingRefused $refused) {
            self::assertSame(['amount'], array_keys($refused->errors));
        }

        // The rest, 720,00 by cheque: the VAT left, 120,00; the cheque waits on 511200.
        $cheque = $payments->record($dossier, new PaymentRequest(PaymentDirection::Receipt, PaymentMethod::Cheque, 'C0019', 'BQ', new \DateTimeImmutable('2026-03-06'), '1234567', Money::cents(72_000), null, [(int) $invoice->getId()]), 'test');
        self::assertSame([['511200', 72_000, 0], ['411000', 0, 72_000], ['445800', 12_000, 0], ['445710', 0, 12_000]], self::lines($cheque->getEntry() ?? throw new \LogicException('entry')));
        self::assertSame(PaymentState::ToDeposit, $cheque->state());
        self::assertSame([], $payments->openInvoices($party, PaymentDirection::Receipt));

        // The cheque remitted: REM-0001, the bank debited, 511200 credited.
        $journal = self::getContainer()->get(JournalRepository::class)->findOneByCode($dossier, 'BQ') ?? throw new \LogicException('BQ');
        try {
            $payments->deposit($journal, [(int) $cheque->getId()], new \DateTimeImmutable('2026-03-05'), 'test');
            self::fail('A remittance never precedes its cheque.');
        } catch (BankingRefused $refused) {
            self::assertSame(['date'], array_keys($refused->errors));
        }
        $deposit = $payments->deposit($journal, [(int) $cheque->getId()], new \DateTimeImmutable('2026-03-09'), 'test');
        self::assertSame('REM-0001', $deposit->getReference());
        self::assertSame([['512000', 72_000, 0], ['511200', 0, 72_000]], self::lines($deposit->getEntry() ?? throw new \LogicException('entry')));
        self::assertSame(PaymentState::Deposited, $cheque->state());
        try {
            $payments->delete($cheque, 'test');
            self::fail('A cheque remitted stays.');
        } catch (BankingRefused) {
        }

        // Lettered once validated: the first payment in part, the second completes the group.
        self::assertStringStartsWith('Validez d’abord l’écriture du règlement', (string) $payments->letteringRefusal($transfer));
        $ledger->validate([$entry, $cheque->getEntry() ?? throw new \LogicException('entry')], 'test');
        self::assertNull($payments->letteringRefusal($transfer));
        self::assertTrue($payments->letter($transfer, 'test')->isPartial());
        self::assertFalse($payments->letter($cheque, 'test')->isPartial());
        $customerLine = array_values(array_filter($invoice->getLines(), static fn (EntryLine $line): bool => '411000' === $line->getAccount()->getNumber()))[0];
        self::assertTrue($customerLine->isLettered() && !$customerLine->isPartiallyLettered());
        self::assertSame('Le règlement est déjà lettré.', $payments->letteringRefusal($cheque));
    }

    public function testABillIsDrawnAndDiscountedAndASupplierPaymentIsDeleted(): void
    {
        $dossier = $this->ledgerDossier();
        $payments = self::getContainer()->get(Payments::class);
        $invoice = $this->entry($dossier, 'VTE', '2026-03-02', 'FV-2026-0102', ['411000/C0019 D 5000.00', '706000 C 4166.67', '445710 C 833.33'], true);

        try {
            $payments->record($dossier, new PaymentRequest(PaymentDirection::Receipt, PaymentMethod::Bill, 'C0019', 'BQ', new \DateTimeImmutable('2026-03-03'), 'LCR-0001', Money::cents(500_000), null, [(int) $invoice->getId()]), 'test');
            self::fail('A bill is drawn in a general journal, with its due date.');
        } catch (BankingRefused $refused) {
            self::assertSame(['journal', 'dueOn'], array_keys($refused->errors));
        }
        $bill = $payments->record($dossier, new PaymentRequest(PaymentDirection::Receipt, PaymentMethod::Bill, 'C0019', 'OD', new \DateTimeImmutable('2026-03-03'), 'LCR-0001', Money::cents(500_000), new \DateTimeImmutable('2026-04-30'), [(int) $invoice->getId()]), 'test');
        self::assertSame([['413000', 500_000, 0], ['411000', 0, 500_000]], self::lines($bill->getEntry() ?? throw new \LogicException('entry')));
        self::assertSame(PaymentState::InPortfolio, $bill->state());

        $journal = self::getContainer()->get(JournalRepository::class)->findOneByCode($dossier, 'BQ') ?? throw new \LogicException('BQ');
        try {
            $payments->remit($bill, BillRemittance::Discount, $journal, new \DateTimeImmutable('2026-03-10'), Money::cents(500_000), Money::zero(), Money::zero(), 'test');
            self::fail('Charges above the bill are refused.');
        } catch (BankingRefused $refused) {
            self::assertSame(['interest'], array_keys($refused->errors));
        }
        // 5 000,00 − 41,67 − 12,00 − 2,40 = 4 943,93 credited by the bank.
        $payments->remit($bill, BillRemittance::Discount, $journal, new \DateTimeImmutable('2026-03-10'), Money::cents(4_167), Money::cents(1_200), Money::cents(240), 'test');
        self::assertSame(PaymentState::Discounted, $bill->state());
        self::assertSame([['512000', 494_393, 0], ['661600', 4_167, 0], ['627500', 1_200, 0], ['445660', 240, 0], ['413000', 0, 500_000]], self::lines($bill->getRemittanceEntry() ?? throw new \LogicException('entry')));

        // A supplier paid on account, then the payment taken back with its draft.
        $supplier = $payments->record($dossier, new PaymentRequest(PaymentDirection::Disbursement, PaymentMethod::Transfer, 'F0001', 'BQ', new \DateTimeImmutable('2026-03-11'), 'VIR-0311', Money::cents(30_000)), 'test');
        self::assertSame([['401000', 30_000, 0], ['512000', 0, 30_000]], self::lines($supplier->getEntry() ?? throw new \LogicException('entry')));
        $entryId = (int) $supplier->getEntry()->getId();
        $payments->delete($supplier, 'test');
        self::assertNull(self::getContainer()->get('doctrine')->getManager()->find(Entry::class, $entryId));
    }

    public function testTheGeneratorWritesTheBooksLessWhatIsLeftOutPlusTheBanksLines(): void
    {
        $dossier = $this->ledgerDossier();
        $customer = $this->entry($dossier, 'BQ', '2026-03-03', 'VIR-DUMA', ['512000 D 4320.00', '411000/C0019 C 4320.00'], true, 'Virement Dumas');
        $cheque = $this->entry($dossier, 'BQ', '2026-03-12', 'CHQ-7702219', ['401000/F0001 D 3248.40', '512000 C 3248.40'], false, 'Chèque Scierie');
        $journal = self::getContainer()->get(JournalRepository::class)->findOneByCode($dossier, 'BQ') ?? throw new \LogicException('BQ');
        $generator = self::getContainer()->get(StatementGenerator::class);
        $defaults = self::getContainer()->get(BankStatements::class)->defaults($journal);

        $rows = $generator->rows($journal, $defaults['from'], $defaults['to']);
        self::assertSame([['VIREMENT DUMAS', 432_000], ['CHEQUE SCIERIE', -324_840]], array_map(static fn ($row): array => [$row->label, $row->amount->cents], $rows));

        try {
            $generator->generate($journal, $defaults['from'], $defaults['to'], [], Money::zero(), Money::zero(), [['date' => new \DateTimeImmutable('2026-04-02'), 'label' => 'VIR X', 'amount' => Money::cents(100), 'error' => null]], 'test');
            self::fail('An unexpected transfer outside the statement is refused.');
        } catch (BankingRefused $refused) {
            self::assertSame(['extra.0.date'], array_keys($refused->errors));
        }

        // The cheque not yet debited is left out; 24,00 of fees, 3,15 of agios, an unexpected transfer.
        $statement = $generator->generate($journal, $defaults['from'], $defaults['to'], [$rows[0]->key], Money::cents(2_400), Money::cents(315), [['date' => new \DateTimeImmutable('2026-03-20'), 'label' => 'Vir sépa Mairie', 'amount' => Money::cents(15_000), 'error' => null]], 'test');
        self::assertSame([
            ['2026-03-03', 'VIREMENT DUMAS', 432_000],
            ['2026-03-20', 'VIR SEPA MAIRIE', 15_000],
            ['2026-03-31', 'FRAIS TENUE DE COMPTE', -2_400],
            ['2026-03-31', 'AGIOS ARRETE DE COMPTE', -315],
        ], array_map(static fn ($line): array => [$line->getDate()->format('Y-m-d'), $line->getLabel(), $line->getAmount()->cents], $statement->getLines()));
        // 4 320,00 + 150,00 − 24,00 − 3,15 = 4 442,85.
        self::assertSame(444_285, $statement->getClosingBalance()->cents);
        self::assertSame(1, $statement->getNumber());
        self::assertNotNull($customer->getId());
        self::assertNotNull($cheque->getId());
    }

    /** @return list<array{string, int, int}> */
    private static function lines(Entry $entry): array
    {
        return array_map(static fn (EntryLine $line): array => [$line->getAccount()->getNumber(), $line->getDebit()->cents, $line->getCredit()->cents], $entry->getLines());
    }
}
