<?php

declare(strict_types=1);

namespace App\Tests\Service\Banking\Proposal;

use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Accounting\AssistanceLevel;
use App\Enum\Banking\PaymentDirection;
use App\Enum\ThirdParty\PaymentMethod;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Repository\Accounting\AccountRepository;
use App\Service\Banking\BankingRefused;
use App\Service\Banking\Payment\PaymentRequest;
use App\Service\Banking\Payment\Payments;
use App\Service\Banking\Proposal\PaymentProposals;
use App\Service\Banking\Proposal\ProposalRow;
use App\Service\Shared\Assistance\Assistance;
use App\Service\Shared\Money;
use App\Tests\Support\BuildsLedger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The suppliers' payment proposal (step 2.7) in BuildsLedger's dossier (date 15/03/2026) plus a
 * second supplier, F0002 « Quincaillerie Faure » on 401000 (401FAUR): what is due by a day, the « bon
 * à payer » given and taken back, the transfer order refused for an invoice without it and - at G
 * and B, not at A - for a supplier holding a payment that settles no invoice, then one transfer per
 * supplier recorded through the payments' door.
 */
final class PaymentProposalsTest extends KernelTestCase
{
    use BuildsLedger;

    public function testApprovedInvoicesArePaidOneTransferPerSupplier(): void
    {
        $dossier = $this->ledgerDossier();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $faure = new ThirdParty($dossier, 'F0002', ThirdPartyKind::Supplier, 'Quincaillerie Faure');
        $faure->keepOn(self::getContainer()->get(AccountRepository::class)->findOneByNumber($dossier, '401000') ?? throw new \LogicException('401000'), '401FAUR');
        $entityManager->persist($faure);
        $entityManager->flush();
        $proposals = self::getContainer()->get(PaymentProposals::class);

        $scierie1 = $this->entry($dossier, 'ACH', '2026-02-10', 'FA-0001', ['601000 D 1000.00', '445660 D 200.00', '401000/F0001 C 1200.00'], true);
        $scierie2 = $this->entry($dossier, 'ACH', '2026-03-01', 'FA-0002', ['601000 D 500.00', '445660 D 100.00', '401000/F0001 C 600.00']);
        $faure1 = $this->entry($dossier, 'ACH', '2026-03-05', 'F26-0042', ['606300 D 100.00', '445660 D 20.00', '401000/F0002 C 120.00'], true);
        $later = $this->entry($dossier, 'ACH', '2026-03-20', 'F26-0050', ['606300 D 50.00', '445660 D 10.00', '401000/F0002 C 60.00']);

        // Due by the dossier's date (15/03): the three first, earliest first; by the 31/03, the fourth too.
        $ids = static fn (array $rows): array => array_map(static fn (ProposalRow $row): string => $row->invoice->entry->getDocument(), $rows);
        self::assertSame(['FA-0001', 'FA-0002', 'F26-0042'], $ids($proposals->rows($dossier, new \DateTimeImmutable('2026-03-15'))));
        self::assertSame(['FA-0001', 'FA-0002', 'F26-0042', 'F26-0050'], $ids($proposals->rows($dossier, new \DateTimeImmutable('2026-03-31'))));

        // The bon à payer: given to two, once only; taken back from one.
        self::assertSame(2, $proposals->approve($dossier, [(int) $scierie1->getId(), (int) $faure1->getId()], true, 'test'));
        self::assertSame(0, $proposals->approve($dossier, [(int) $scierie1->getId()], true, 'test'));
        self::assertSame(1, $proposals->approve($dossier, [(int) $scierie2->getId(), (int) $faure1->getId()], true, 'test'));
        self::assertSame(1, $proposals->approve($dossier, [(int) $scierie2->getId()], false, 'test'));
        self::assertSame([true, false, true, false], array_map(static fn (ProposalRow $row): bool => $row->isApproved(), $proposals->rows($dossier, null)));

        // An invoice without its bon à payer refuses the order.
        $warnings = Assistance::at(AssistanceLevel::Warnings);
        $order = $proposals->order($dossier, [(int) $scierie1->getId(), (int) $scierie2->getId()], $warnings);
        self::assertSame(['F0001'], array_keys($order->faults));

        // F0002 paid 50,00 on account: refused at G and B, signalled at A, silent at 0.
        $payments = self::getContainer()->get(Payments::class);
        $payments->record($dossier, new PaymentRequest(PaymentDirection::Disbursement, PaymentMethod::Transfer, 'F0002', 'BQ', new \DateTimeImmutable('2026-03-06'), 'ACOMPTE', Money::cents(5_000)), 'test');
        self::assertSame([(int) $faure->getId() => 5_000], array_map(static fn (Money $one): int => $one->cents, $proposals->unmatched($dossier)));
        $both = [(int) $scierie1->getId(), (int) $faure1->getId()];
        self::assertSame(['F0002'], array_keys($proposals->order($dossier, $both, Assistance::at(AssistanceLevel::Blocking))->faults));
        self::assertSame(['F0002'], array_keys($proposals->order($dossier, $both, Assistance::at(AssistanceLevel::Guided))->faults));
        $order = $proposals->order($dossier, $both, $warnings);
        self::assertSame([[], ['F0002']], [$order->faults, array_keys($order->warnings)]);
        self::assertSame([[], []], [($none = $proposals->order($dossier, $both, Assistance::at(AssistanceLevel::None)))->faults, $none->warnings]);

        // One transfer per supplier, in code order: 1 200,00 to the Scierie, 120,00 to Faure.
        self::assertSame([['F0001', 120_000], ['F0002', 12_000]], array_map(static fn (array $transfer): array => [$transfer['supplier']->getCode(), $transfer['amount']->cents], $order->transfers));
        self::assertSame(132_000, $order->total()->cents);

        try {
            $proposals->generate($dossier, $both, 'XX', new \DateTimeImmutable('2026-03-15'), $warnings, 'test');
            self::fail('A journal that is not a bank journal is refused.');
        } catch (BankingRefused $refused) {
            self::assertSame(['journal'], array_keys($refused->errors));
        }
        try {
            $proposals->generate($dossier, $both, 'BQ', new \DateTimeImmutable('2026-03-15'), Assistance::at(AssistanceLevel::Blocking), 'test');
            self::fail('A double payment is refused at B.');
        } catch (BankingRefused $refused) {
            self::assertSame(['F0002'], array_keys($refused->errors));
        }

        $outcome = $proposals->generate($dossier, $both, 'BQ', new \DateTimeImmutable('2026-03-15'), $warnings, 'test');
        self::assertSame([], $outcome->refused);
        self::assertSame([['VIR-20260315-F0001', 120_000, 'FA-0001'], ['VIR-20260315-F0002', 12_000, 'F26-0042']], array_map(static fn ($payment): array => [$payment->getReference(), $payment->getAmount()->cents, $payment->getAllocations()[0]->getInvoice()->getDocument()], $outcome->payments));
        // What was paid leaves the proposal.
        self::assertSame(['FA-0002', 'F26-0050'], $ids($proposals->rows($dossier, null)));
        self::assertNotNull($later->getId());
    }
}
