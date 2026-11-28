<?php

declare(strict_types=1);

namespace App\Service\Banking\Proposal;

use App\Entity\Accounting\Journal;
use App\Entity\Banking\PaymentApproval;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Audit\AuditAction;
use App\Enum\Banking\PaymentDirection;
use App\Enum\ThirdParty\PaymentMethod;
use App\Repository\Banking\PaymentApprovalRepository;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Service\Audit\AuditTrail;
use App\Service\Banking\BankingRefused;
use App\Service\Banking\Payment\PaymentRequest;
use App\Service\Banking\Payment\Payments;
use App\Service\Shared\Assistance\Assistance;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The preparation of the suppliers' payments (step 2.7; design-ux.md § 3.2, Achats › Règlements ›
 * « Proposition de paiement »): the invoices still to pay due by a day, each given its « bon à payer »
 * (PaymentApproval) or not, then a transfer order of the approved invoices chosen - one transfer per
 * supplier -, whose payments Payments::record() makes, each with its entry through the gateway. The
 * proposal never writes an entry itself. Inside the dossier's context.
 */
final readonly class PaymentProposals
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Payments $payments,
        private PaymentApprovalRepository $approvals,
        private ThirdPartyRepository $thirdParties,
        private AuditTrail $auditTrail,
    ) {
    }

    /**
     * Every supplier invoice still to pay due by `until` (null: whatever its due date), earliest due
     * first.
     *
     * @return list<ProposalRow>
     */
    public function rows(Dossier $dossier, ?\DateTimeImmutable $until): array
    {
        $suppliers = [];
        foreach ($this->thirdParties->findByDossier($dossier) as $party) {
            $suppliers[(int) $party->getId()] = $party;
        }
        $found = [];
        foreach ($this->payments->openInvoicesOf($dossier, PaymentDirection::Disbursement) as $partyId => $invoices) {
            foreach ($invoices as $invoice) {
                if (isset($suppliers[$partyId]) && (null === $until || $invoice->dueOn <= $until)) {
                    $found[] = [$suppliers[$partyId], $invoice];
                }
            }
        }
        $approvals = $this->approvals->byInvoice(array_map(static fn (array $one): int => (int) $one[1]->entry->getId(), $found));
        $rows = array_map(static fn (array $one): ProposalRow => new ProposalRow($one[0], $one[1], $approvals[(int) $one[1]->entry->getId()] ?? null), $found);
        usort($rows, static fn (ProposalRow $a, ProposalRow $b): int => [$a->invoice->dueOn->format('Y-m-d'), $a->supplier->getCode(), $a->id()] <=> [$b->invoice->dueOn->format('Y-m-d'), $b->supplier->getCode(), $b->id()]);

        return $rows;
    }

    /**
     * What each supplier carries that settles no invoice (Payments::unmatchedPayments()), by id.
     *
     * @return array<int, Money>
     */
    public function unmatched(Dossier $dossier): array
    {
        return $this->payments->unmatchedPayments($dossier, PaymentDirection::Disbursement);
    }

    /**
     * The « bon à payer » given to the invoices chosen still to pay (those already approved left as
     * they are), or taken back. Answers how many changed; refused when none of them is to pay.
     *
     * @param list<int> $invoiceIds
     */
    public function approve(Dossier $dossier, array $invoiceIds, bool $approve, User|string $actor): int
    {
        $chosen = array_values(array_filter($this->rows($dossier, null), static fn (ProposalRow $row): bool => \in_array($row->id(), $invoiceIds, true)));
        if ([] === $chosen) {
            throw new BankingRefused('Cochez les factures à approuver : aucune n’est plus à payer.');
        }
        $changed = [];
        foreach ($chosen as $row) {
            if ($approve && null === $row->approval) {
                $this->entityManager->persist(new PaymentApproval($dossier, $row->invoice->entry, $dossier->getDossierDate(), $actor instanceof User ? $actor : null));
                $changed[] = $row;
            } elseif (!$approve && null !== $row->approval) {
                $this->entityManager->remove($row->approval);
                $changed[] = $row;
            }
        }
        if ([] === $changed) {
            return 0;
        }
        $this->auditTrail->inDossier($dossier, $approve ? AuditAction::PaymentApproved : AuditAction::PaymentApprovalWithdrawn, $actor, \sprintf(
            '%s : %s',
            $approve ? 'Bon à payer donné' : 'Bon à payer retiré',
            implode(', ', array_map(static fn (ProposalRow $row): string => $row->supplier->getName().' '.$row->invoice->entry->getDocument(), $changed)),
        ), ['invoices' => array_map(static fn (ProposalRow $row): int => $row->id(), $changed)]);
        $this->entityManager->flush();

        return \count($changed);
    }

    /**
     * The transfer order of the invoices chosen: one transfer per supplier, for what is left to pay of
     * its invoices. An invoice no longer to pay, or not approved, is a fault; so is a supplier carrying
     * a payment that settles no invoice when the level refuses a double payment - a warning when it
     * only signals one.
     *
     * @param list<int> $invoiceIds
     */
    public function order(Dossier $dossier, array $invoiceIds, Assistance $assistance): TransferOrder
    {
        $byId = [];
        foreach ($this->rows($dossier, null) as $row) {
            $byId[$row->id()] = $row;
        }
        $faults = [];
        $warnings = [];
        $groups = [];
        foreach (array_values(array_unique($invoiceIds)) as $id) {
            $row = $byId[$id] ?? null;
            if (null === $row) {
                $faults['invoices'] = 'Une facture cochée n’est plus à payer : affichez de nouveau la proposition.';
                continue;
            }
            if (!$row->isApproved()) {
                $faults[$row->supplier->getCode()] = \sprintf('La facture %s de %s n’a pas son bon à payer.', $row->invoice->entry->getDocument(), $row->supplier->getName());
                continue;
            }
            $groups[$row->supplier->getCode()][] = $row;
        }
        if ([] === $invoiceIds) {
            $faults['invoices'] = 'Cochez les factures à payer.';
        }
        ksort($groups, \SORT_STRING);
        $unmatched = $this->unmatched($dossier);
        $transfers = [];
        foreach ($groups as $code => $rows) {
            $supplier = $rows[0]->supplier;
            $amount = Money::sum(array_map(static fn (ProposalRow $row): Money => $row->invoice->remaining(), $rows));
            $transfers[] = ['supplier' => $supplier, 'rows' => $rows, 'amount' => $amount];
            $open = $unmatched[(int) $supplier->getId()] ?? null;
            if (null !== $open && $assistance->signalsDoublePayment()) {
                $sentence = \sprintf('%s porte déjà %s au débit qui ne règle aucune facture (un règlement ou un avoir non rapproché) : lettrez-le ou affectez-le avant de payer de nouveau.', $supplier->getName(), FrenchFormat::amount($open));
                if ($assistance->refusesDoublePayment()) {
                    $faults[(string) $code] = $sentence;
                } else {
                    $warnings[(string) $code] = $sentence;
                }
            }
        }

        return new TransferOrder($transfers, $faults, $warnings);
    }

    /**
     * The order's payments made: a transfer per supplier from the bank journal chosen at the date
     * chosen, referenced « VIR-AAAAMMJJ-<code> », through Payments::record() - which checks the
     * profile's right on the journal and asks the gateway for the entry. A fault of the order refuses
     * the whole; a refusal of one transfer never stops the others.
     *
     * @param list<int> $invoiceIds
     */
    public function generate(Dossier $dossier, array $invoiceIds, string $journal, ?\DateTimeImmutable $on, Assistance $assistance, User|string $actor): PaymentRunOutcome
    {
        $order = $this->order($dossier, $invoiceIds, $assistance);
        if ([] !== $order->faults) {
            throw new BankingRefused(array_values($order->faults)[0], $order->faults);
        }
        $errors = [];
        if (!\in_array($journal, array_map(static fn (Journal $one): string => $one->getCode(), $this->payments->bankJournals($dossier)), true)) {
            $errors['journal'] = 'Choisissez le journal de banque des virements.';
        }
        if (null === $on) {
            $errors['date'] = 'Indiquez la date des virements.';
        }
        if ([] !== $errors || null === $on) {
            throw new BankingRefused((string) reset($errors), $errors);
        }
        $made = [];
        $refused = [];
        foreach ($order->transfers as $transfer) {
            $supplier = $transfer['supplier'];
            try {
                $made[] = $this->payments->record($dossier, new PaymentRequest(
                    PaymentDirection::Disbursement,
                    PaymentMethod::Transfer,
                    $supplier->getCode(),
                    $journal,
                    $on,
                    'VIR-'.$on->format('Ymd').'-'.$supplier->getCode(),
                    $transfer['amount'],
                    null,
                    array_map(static fn (ProposalRow $row): int => $row->id(), $transfer['rows']),
                ), $actor);
            } catch (BankingRefused $refusal) {
                $refused[$supplier->getName()] = $refusal->getMessage();
            }
        }

        return new PaymentRunOutcome($made, $refused);
    }
}
