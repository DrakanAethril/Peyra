<?php

declare(strict_types=1);

namespace App\Service\Banking\Payment;

use App\Entity\Accounting\Entry;
use App\Entity\Accounting\EntryLine;
use App\Entity\Accounting\Journal;
use App\Entity\Banking\ChequeDeposit;
use App\Entity\Banking\Payment;
use App\Entity\Banking\PaymentAllocation;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Accounting\AccountingModule;
use App\Enum\Accounting\JournalType;
use App\Enum\Audit\AuditAction;
use App\Enum\Banking\BillRemittance;
use App\Enum\Banking\PaymentDirection;
use App\Enum\Dossier\AccessLevel;
use App\Enum\ThirdParty\PaymentMethod;
use App\Repository\Accounting\JournalRepository;
use App\Repository\Banking\ChequeDepositRepository;
use App\Repository\Banking\PaymentRepository;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Service\Accounting\Gateway\AccountingGateway;
use App\Service\Accounting\Gateway\EntryProposal;
use App\Service\Accounting\Ledger\EntryDraft;
use App\Service\Accounting\Ledger\EntryRefused;
use App\Service\Accounting\Lettering\Lettering;
use App\Service\Accounting\Lettering\LetteringResult;
use App\Service\Audit\AuditTrail;
use App\Service\Banking\BankingRefused;
use App\Service\Dossier\Rights\DossierRights;
use App\Service\Sales\SalesEntries;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one door of the payments (step 2.6b; lot 2, « Banque »): a payment, a remittance of cheques, a
 * bill remitted to the bank - each an operation that asks AccountingGateway for its entry (module
 * Banque, the dossier's behaviour decides whether it is written), recorded in the dossier's trail.
 * A payment settles invoices (PaymentAllocation) and, once its entry and theirs are validated, is
 * lettered with them through Lettering. A refusal is BankingRefused, named by field, and writes
 * nothing. Inside the dossier's context.
 */
final readonly class Payments
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PaymentRepository $payments,
        private ChequeDepositRepository $deposits,
        private JournalRepository $journals,
        private ThirdPartyRepository $thirdParties,
        private AccountingGateway $gateway,
        private Lettering $lettering,
        private AuditTrail $auditTrail,
        private DossierRights $rights,
    ) {
    }

    /**
     * The methods a payment of this way may take: a bill is drawn on a customer only.
     *
     * @return list<PaymentMethod>
     */
    public static function methods(PaymentDirection $direction): array
    {
        $methods = [];
        foreach (PaymentMethod::cases() as $method) {
            if (PaymentMethod::Bill !== $method || PaymentDirection::Receipt === $direction) {
                $methods[] = $method;
            }
        }

        return $methods;
    }

    /**
     * The journals a payment by this method goes through: the bank journals (with their account),
     * the cash journals for cash, the general journals for a bill drawn.
     *
     * @return list<Journal>
     */
    public function journalsFor(Dossier $dossier, PaymentMethod $method): array
    {
        $type = match ($method) {
            PaymentMethod::Cash => JournalType::Cash,
            PaymentMethod::Bill => JournalType::General,
            default => JournalType::Bank,
        };

        return array_values(array_filter($this->journals->findByType($dossier, $type), static fn (Journal $journal): bool => JournalType::General === $type || null !== $journal->getCounterpartAccount()));
    }

    /** @return list<Journal> the bank journals, with their account */
    public function bankJournals(Dossier $dossier): array
    {
        return $this->journalsFor($dossier, PaymentMethod::Transfer);
    }

    /** @return list<ThirdParty> the customers or suppliers kept on an account with their code */
    public function parties(Dossier $dossier, PaymentDirection $direction): array
    {
        return array_values(array_filter($this->thirdParties->findByDossier($dossier), static fn (ThirdParty $party): bool => $party->getKind() === $direction->thirdPartyKind() && null !== $party->getAccount() && null !== $party->getAuxiliaryCode()));
    }

    /**
     * The invoices of a third party a payment may settle, the oldest first: the entries whose lines on
     * its account are not lettered (a customer's debits, a supplier's credits), less what payments
     * already took of them.
     *
     * @return list<OpenInvoice>
     */
    public function openInvoices(ThirdParty $party, PaymentDirection $direction): array
    {
        return $this->readOpen($party->getDossier(), $direction, $party)[0][(int) $party->getId()] ?? [];
    }

    /**
     * The same for every customer or every supplier of the dossier at once (the payment proposal of
     * step 2.7), by third party id.
     *
     * @return array<int, list<OpenInvoice>>
     */
    public function openInvoicesOf(Dossier $dossier, PaymentDirection $direction): array
    {
        return $this->readOpen($dossier, $direction, null)[0];
    }

    /**
     * What each third party has paid or been paid that settles no invoice yet (step 2.7): its
     * unlettered lines on the other side of its account - a supplier's debits, a customer's credits -
     * less what payments allocated to its open invoices. Paying an invoice of a supplier who carries
     * such an amount risks paying it twice. By third party id, positive amounts only.
     *
     * @return array<int, Money>
     */
    public function unmatchedPayments(Dossier $dossier, PaymentDirection $direction): array
    {
        return $this->readOpen($dossier, $direction, null)[1];
    }

    /**
     * @return array{0: array<int, list<OpenInvoice>>, 1: array<int, Money>}
     */
    private function readOpen(Dossier $dossier, PaymentDirection $direction, ?ThirdParty $only): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('l', 'e', 't')->from(EntryLine::class, 'l')->join('l.entry', 'e')->join('l.thirdParty', 't')
            ->andWhere('e.dossier = :dossier')->setParameter('dossier', $dossier)
            ->andWhere('t.kind = :kind')->setParameter('kind', $direction->thirdPartyKind())
            ->andWhere('l.account = t.account')
            ->andWhere('l.letter IS NULL')
            ->orderBy('e.entryDate')->addOrderBy('e.id');
        if (null !== $only) {
            $query->andWhere('l.thirdParty = :party')->setParameter('party', $only);
        }
        /** @var list<EntryLine> $lines */
        $lines = $query->getQuery()->getResult();
        // Keyed by third party then entry: an opening entry names several third parties.
        $totals = [];
        $due = [];
        $entries = [];
        $other = [];
        foreach ($lines as $line) {
            $party = (int) $line->getThirdParty()?->getId();
            $owed = PaymentDirection::Receipt === $direction ? $line->getDebit() : $line->getCredit();
            $paid = PaymentDirection::Receipt === $direction ? $line->getCredit() : $line->getDebit();
            if ($paid->isPositive()) {
                $other[$party] = ($other[$party] ?? Money::zero())->plus($paid);
            }
            if (!$owed->isPositive()) {
                continue;
            }
            $entry = $line->getEntry();
            $id = (int) $entry->getId();
            $entries[$party][$id] = $entry;
            $totals[$party][$id] = ($totals[$party][$id] ?? Money::zero())->plus($owed);
            $day = $line->getDueDate() ?? $entry->getEntryDate();
            $due[$party][$id] = isset($due[$party][$id]) && $due[$party][$id] < $day ? $due[$party][$id] : $day;
        }
        $allocated = [];
        $transferred = [];
        $ids = [];
        foreach ($entries as $byEntry) {
            $ids = [...$ids, ...array_keys($byEntry)];
        }
        foreach ($this->payments->allocationsOf(array_values(array_unique($ids))) as $allocation) {
            $party = (int) $allocation->getPayment()->getThirdParty()->getId();
            $id = (int) $allocation->getInvoice()->getId();
            $allocated[$party][$id] = ($allocated[$party][$id] ?? Money::zero())->plus($allocation->getAmount());
            $transferred[$party][$id] = ($transferred[$party][$id] ?? Money::zero())->plus($allocation->getVatTransferred());
        }
        $open = [];
        $unmatched = [];
        foreach ($entries as $party => $byEntry) {
            foreach ($byEntry as $id => $entry) {
                $taken = $allocated[$party][$id] ?? Money::zero();
                $other[$party] = ($other[$party] ?? Money::zero())->minus($taken);
                $invoice = new OpenInvoice($entry, $totals[$party][$id], $taken, self::pendingVat($entry, $direction), $transferred[$party][$id] ?? Money::zero(), $due[$party][$id]);
                if ($invoice->remaining()->isPositive()) {
                    $open[$party][] = $invoice;
                }
            }
        }
        foreach ($other as $party => $amount) {
            if ($amount->isPositive()) {
                $unmatched[$party] = $amount;
            }
        }

        return [$open, $unmatched];
    }

    /**
     * The payment checked, then its entry asked of the gateway, then the payment written with what it
     * settles. Refused - a field, or the entry the Ledger would not take -, nothing is written.
     */
    public function record(Dossier $dossier, PaymentRequest $request, User|string $actor): Payment
    {
        $errors = [];
        $method = $request->method;
        if (null === $method || !\in_array($method, self::methods($request->direction), true)) {
            $errors['method'] = 'Choisissez le mode de règlement.';
        }
        $party = '' === $request->thirdParty ? null : $this->thirdParties->findOneByCode($dossier, $request->thirdParty);
        $kind = $request->direction->thirdPartyKind();
        if (null === $party || $party->getKind() !== $kind) {
            $errors['thirdParty'] = PaymentDirection::Receipt === $request->direction ? 'Choisissez le client.' : 'Choisissez le fournisseur.';
        } elseif (null === $party->getAccount() || null === $party->getAuxiliaryCode()) {
            $errors['thirdParty'] = \sprintf('%s n’est tenu sur aucun compte collectif : donnez-lui son compte dans sa fiche.', $party->getName());
        }
        $journal = null;
        foreach (null === $method ? [] : $this->journalsFor($dossier, $method) as $one) {
            if ($one->getCode() === $request->journal) {
                $journal = $one;
            }
        }
        if (null === $journal && null !== $method) {
            $errors['journal'] = match ($method) {
                PaymentMethod::Cash => 'Choisissez le journal de caisse.',
                PaymentMethod::Bill => 'Choisissez le journal des opérations diverses où l’effet est tiré.',
                default => 'Choisissez le journal de banque.',
            };
        } elseif (null !== $journal && null !== ($refusal = $this->rights->journalRefusal($journal, $actor, AccessLevel::Enter))) {
            $errors['journal'] = $refusal;
        }
        if (null === $request->date) {
            $errors['date'] = 'Indiquez la date du règlement.';
        }
        $reference = trim($request->reference);
        if ('' === $reference) {
            $errors['reference'] = PaymentMethod::Cheque === $method ? 'Indiquez le numéro du chèque.' : 'Indiquez la référence du règlement (numéro de chèque, de virement, de l’effet).';
        } elseif (mb_strlen($reference) > 40) {
            $errors['reference'] = 'La référence tient en 40 caractères.';
        }
        $amount = $request->amount;
        if (null === $amount || !$amount->isPositive()) {
            $errors['amount'] = 'Indiquez le montant réglé.';
        }
        if (PaymentMethod::Bill === $method) {
            if (null === $request->dueOn) {
                $errors['dueOn'] = 'Indiquez l’échéance de l’effet.';
            } elseif (null !== $request->date && $request->dueOn < $request->date) {
                $errors['dueOn'] = 'L’échéance de l’effet suit le jour où il est tiré.';
            }
        }

        $chosen = [];
        $parts = [];
        if (null !== $party) {
            $byId = [];
            foreach ($this->openInvoices($party, $request->direction) as $invoice) {
                $byId[(int) $invoice->entry->getId()] = $invoice;
            }
            foreach (array_values(array_unique($request->invoices)) as $id) {
                if (!isset($byId[$id])) {
                    $errors['invoices'] = 'Une facture choisie n’est plus à régler : affichez de nouveau le formulaire.';
                    break;
                }
                $chosen[] = $byId[$id];
            }
            if (!isset($errors['invoices']) && [] !== $chosen && null !== $amount && $amount->isPositive()) {
                $parts = PaymentRules::allocate($amount, array_map(static fn (OpenInvoice $invoice): Money => $invoice->remaining(), $chosen));
                if (null === $parts) {
                    $errors['amount'] = \sprintf('Le montant dépasse le reste dû des factures choisies (%s) : choisissez-en d’autres, ou réglez le surplus à part.', FrenchFormat::amount(Money::sum(array_map(static fn (OpenInvoice $invoice): Money => $invoice->remaining(), $chosen))));
                }
            }
        }
        if ([] !== $errors || null === $method || null === $party || null === $journal || null === $request->date || null === $amount || null === $parts) {
            throw new BankingRefused((string) reset($errors), $errors);
        }

        $vat = [];
        foreach ($chosen as $i => $invoice) {
            $vat[$i] = PaymentDirection::Receipt === $request->direction
                ? PaymentRules::vatTransfer($invoice->pendingVat, $invoice->total, $invoice->allocated, $invoice->transferredVat, $parts[$i])
                : Money::zero();
        }
        $draft = $this->paymentDraft($request->direction, $method, $journal, $party, $request->date, $reference, $amount, Money::sum($vat), $request->dueOn);
        $entry = $this->submit($dossier, $draft, $reference, $actor);

        $payment = new Payment($dossier, $request->direction, $method, $party, $journal, $request->date, $reference, $amount, PaymentMethod::Bill === $method ? $request->dueOn : null);
        if (null !== $entry) {
            $payment->linkEntry($entry);
        }
        $this->entityManager->persist($payment);
        foreach ($chosen as $i => $invoice) {
            $this->entityManager->persist(new PaymentAllocation($payment, $invoice->entry, $parts[$i], $vat[$i]));
        }
        $this->auditTrail->inDossier($dossier, AuditAction::PaymentRecorded, $actor, \sprintf(
            '%s %s de %s, %s, %s : %s%s',
            $request->direction->label(),
            mb_strtolower($method->label()),
            $party->getName(),
            $reference,
            FrenchFormat::date($request->date),
            FrenchFormat::amount($amount),
            [] === $chosen ? ' (sans facture)' : ' ('.implode(', ', array_map(static fn (OpenInvoice $invoice): string => $invoice->entry->getDocument(), $chosen)).')',
        ), ['journal' => $journal->getCode(), 'reference' => $reference]);
        $this->entityManager->flush();
        $this->entityManager->refresh($payment);

        return $payment;
    }

    /**
     * A payment taken back: refused once its entry is validated (it would be reversed, not deleted),
     * once its cheque is remitted or its bill handed to the bank; its entry still in the brouillard
     * goes with it.
     */
    public function delete(Payment $payment, User|string $actor): void
    {
        $refusal = $this->rights->journalRefusal($payment->getJournal(), $actor, AccessLevel::Enter);
        if (null !== $refusal) {
            throw new BankingRefused($refusal);
        }
        if (null !== $payment->getDeposit()) {
            throw new BankingRefused(\sprintf('Ce chèque est remis en banque (%s) : le règlement ne se supprime plus.', $payment->getDeposit()->getReference()));
        }
        if (null !== $payment->getRemittance()) {
            throw new BankingRefused('Cet effet est remis à la banque : le règlement ne se supprime plus.');
        }
        $entry = $payment->getEntry();
        if (null !== $entry && $entry->isValidated()) {
            throw new BankingRefused('L’écriture du règlement est validée : elle ne se supprime plus, elle se contrepasse dans la saisie.');
        }
        $dossier = $payment->getDossier();
        if (null !== $entry) {
            $this->gateway->withdraw($entry, $dossier->getDossierDate(), $actor);
        }
        $this->auditTrail->inDossier($dossier, AuditAction::PaymentDeleted, $actor, \sprintf('%s %s de %s supprimé (%s)', $payment->getDirection()->label(), $payment->getReference(), $payment->getThirdParty()->getName(), FrenchFormat::amount($payment->getAmount())), ['reference' => $payment->getReference()]);
        foreach ($payment->getAllocations() as $allocation) {
            $this->entityManager->remove($allocation);
        }
        $this->entityManager->remove($payment);
        $this->entityManager->flush();
    }

    /**
     * The payment's line on the third party's account and the invoices' it settles, lettered together
     * (Lettering decides whether a partial group may stay, by level): once all their entries are
     * validated; refused otherwise, with the reason `letteringRefusal()` gives.
     */
    public function letter(Payment $payment, User|string $actor): LetteringResult
    {
        $refusal = $this->letteringRefusal($payment);
        if (null !== $refusal) {
            throw new BankingRefused($refusal);
        }
        $result = $this->lettering->letter($payment->getDossier(), $this->letteringLines($payment), $actor);
        $this->auditTrail->inDossier($payment->getDossier(), AuditAction::PaymentLettered, $actor, \sprintf('Règlement %s de %s lettré (%s)', $payment->getReference(), $payment->getThirdParty()->getName(), $result->letter), ['reference' => $payment->getReference(), 'letter' => $result->letter]);
        $this->entityManager->flush();

        return $result;
    }

    /** Why the payment is not lettered from its fiche, null when it may be. */
    public function letteringRefusal(Payment $payment): ?string
    {
        $entry = $payment->getEntry();
        if (null === $entry) {
            return 'Le règlement n’a pas d’écriture : lettrez-le depuis la consultation du compte.';
        }
        if ([] === $payment->getAllocations()) {
            return 'Le règlement ne règle aucune facture.';
        }
        if (!$entry->isValidated()) {
            return 'Validez d’abord l’écriture du règlement : seules les écritures validées se lettrent.';
        }
        foreach ($payment->getAllocations() as $allocation) {
            if (!$allocation->getInvoice()->isValidated()) {
                return \sprintf('Validez d’abord l’écriture de la facture %s.', $allocation->getInvoice()->getDocument());
            }
        }
        foreach ($this->partyLines($payment, $entry) as $line) {
            if (!$line->isLettered()) {
                return null;
            }
        }

        return 'Le règlement est déjà lettré.';
    }

    /**
     * The cheques chosen, received into one bank journal, remitted together: one entry, the bank
     * debited of their total, 511200 credited cheque by cheque, its pièce the slip's reference.
     *
     * @param list<int> $paymentIds
     */
    public function deposit(Journal $journal, array $paymentIds, ?\DateTimeImmutable $on, User|string $actor): ChequeDeposit
    {
        $dossier = $journal->getDossier();
        $refusal = $this->rights->journalRefusal($journal, $actor, AccessLevel::Enter);
        if (null !== $refusal) {
            throw new BankingRefused($refusal, ['journal' => $refusal]);
        }
        $bank = $journal->getCounterpartAccount();
        if (JournalType::Bank !== $journal->getType() || null === $bank) {
            throw new BankingRefused('Choisissez un journal de banque.', ['journal' => 'Choisissez un journal de banque.']);
        }
        $cheques = array_values(array_filter($this->payments->findChequesToDeposit($journal), static fn (Payment $payment): bool => \in_array($payment->getId(), $paymentIds, true)));
        $errors = [];
        if ([] === $cheques) {
            $errors['cheques'] = 'Cochez les chèques à remettre.';
        } elseif (\count($cheques) !== \count(array_unique($paymentIds))) {
            $errors['cheques'] = 'Un chèque coché n’est plus à remettre : affichez de nouveau la liste.';
        }
        if (null === $on) {
            $errors['date'] = 'Indiquez la date de la remise.';
        } else {
            foreach ($cheques as $cheque) {
                if ($cheque->getPaidOn() > $on) {
                    $errors['date'] = \sprintf('Le chèque %s est reçu le %s : la remise ne le précède pas.', $cheque->getReference(), FrenchFormat::date($cheque->getPaidOn()));
                    break;
                }
            }
        }
        if ([] !== $errors) {
            throw new BankingRefused((string) reset($errors), $errors);
        }
        $number = max($this->deposits->lastNumber($dossier), $this->lastSlipInBooks($dossier)) + 1;
        $deposit = new ChequeDeposit($dossier, $journal, $number, $on);
        $draft = PaymentEntries::chequeDeposit($journal->getCode(), $bank->getNumber(), $on, $deposit->getReference(), array_map(static fn (Payment $cheque): array => ['label' => mb_substr($cheque->getThirdParty()->getName().' chèque '.$cheque->getReference(), 0, 200), 'amount' => $cheque->getAmount()], $cheques));
        $entry = $this->submit($dossier, $draft, $deposit->getReference(), $actor);
        if (null !== $entry) {
            $deposit->linkEntry($entry);
        }
        $this->entityManager->persist($deposit);
        foreach ($cheques as $cheque) {
            $cheque->depositedIn($deposit);
        }
        $total = Money::sum(array_map(static fn (Payment $cheque): Money => $cheque->getAmount(), $cheques));
        $this->auditTrail->inDossier($dossier, AuditAction::ChequesDeposited, $actor, \sprintf('%s au journal %s : %d chèque%s, %s', $deposit->getReference(), $journal->getCode(), \count($cheques), \count($cheques) > 1 ? 's' : '', FrenchFormat::amount($total)), ['journal' => $journal->getCode(), 'number' => $number]);
        $this->entityManager->flush();

        return $deposit;
    }

    /**
     * A bill in the portfolio handed to the bank: for collection (511300 / 413000), or for discount
     * (the bank's net, the interest, the commission and its VAT / 413000).
     */
    public function remit(Payment $bill, BillRemittance $remittance, Journal $journal, ?\DateTimeImmutable $on, Money $interest, Money $commission, Money $commissionVat, User|string $actor): void
    {
        if (!$bill->isBill() || null !== $bill->getRemittance()) {
            throw new BankingRefused('Cet effet n’est plus en portefeuille.');
        }
        $dossier = $bill->getDossier();
        $errors = [];
        $bank = $journal->getCounterpartAccount();
        if (JournalType::Bank !== $journal->getType() || null === $bank) {
            $errors['journal'] = 'Choisissez le journal de la banque.';
        } elseif (null !== ($refusal = $this->rights->journalRefusal($journal, $actor, AccessLevel::Enter))) {
            $errors['journal'] = $refusal;
        }
        if (null === $on) {
            $errors['date'] = 'Indiquez la date de la remise.';
        } elseif ($on < $bill->getPaidOn()) {
            $errors['date'] = \sprintf('L’effet est tiré le %s : la remise ne le précède pas.', FrenchFormat::date($bill->getPaidOn()));
        }
        if (BillRemittance::Discount === $remittance) {
            foreach (['interest' => $interest, 'commission' => $commission, 'commissionVat' => $commissionVat] as $field => $charge) {
                if ($charge->isNegative()) {
                    $errors[$field] = 'Un montant d’agios est positif ou nul.';
                }
            }
            if (!PaymentRules::discountNet($bill->getAmount(), $interest, $commission, $commissionVat)->isPositive()) {
                $errors['interest'] = 'Les agios dépassent le montant de l’effet.';
            }
        }
        if ([] !== $errors || null === $on || null === $bank) {
            throw new BankingRefused((string) reset($errors), $errors);
        }
        $party = $bill->getThirdParty();
        $label = mb_substr(\sprintf('%s effet %s %s', $party->getName(), $bill->getReference(), BillRemittance::Collection === $remittance ? 'à l’encaissement' : 'à l’escompte'), 0, 200);
        $draft = BillRemittance::Collection === $remittance
            ? PaymentEntries::billCollection($journal->getCode(), $party->getCode(), $on, $bill->getReference(), $label, $bill->getAmount())
            : PaymentEntries::billDiscount($journal->getCode(), $bank->getNumber(), $party->getCode(), $on, $bill->getReference(), $label, $bill->getAmount(), $interest, $commission, $commissionVat);
        $entry = $this->submit($dossier, $draft, $bill->getReference(), $actor);
        $bill->remit($remittance, $journal, $on, $entry);
        $this->auditTrail->inDossier($dossier, AuditAction::BillRemitted, $actor, \sprintf('Effet %s de %s %s le %s (%s)', $bill->getReference(), $party->getName(), mb_strtolower($remittance->label()), FrenchFormat::date($on), FrenchFormat::amount($bill->getAmount())), ['reference' => $bill->getReference(), 'remittance' => $remittance->value]);
        $this->entityManager->flush();
    }

    /** The entry a payment asks for, as the gateway receives it. */
    public function paymentDraft(PaymentDirection $direction, PaymentMethod $method, Journal $journal, ThirdParty $party, \DateTimeImmutable $on, string $reference, Money $amount, Money $vatOnReceipts, ?\DateTimeImmutable $dueOn): EntryDraft
    {
        $partyAccount = $party->getAccount()?->getNumber() ?? '';
        $label = mb_substr($party->getName().' '.$reference, 0, 200);
        if (PaymentDirection::Disbursement === $direction) {
            return PaymentEntries::disbursement($journal->getCode(), $journal->getCounterpartAccount()?->getNumber() ?? '', $partyAccount, $party->getCode(), $on, $reference, $label, $amount);
        }
        $debit = match ($method) {
            PaymentMethod::Cheque => PaymentEntries::CHEQUES_TO_CASH,
            PaymentMethod::Bill => PaymentEntries::BILLS_RECEIVABLE,
            default => $journal->getCounterpartAccount()?->getNumber() ?? '',
        };

        return PaymentEntries::receipt($journal->getCode(), $debit, $partyAccount, $party->getCode(), $on, $reference, $label, $amount, $vatOnReceipts, PaymentMethod::Bill === $method ? $dueOn : null);
    }

    /** The gateway's answer: the entry written, or null under « Laisser saisir »; a refusal names its first fault. */
    private function submit(Dossier $dossier, EntryDraft $draft, string $reference, User|string $actor): ?Entry
    {
        try {
            return $this->gateway->submit($dossier, new EntryProposal(AccountingModule::Banking, $draft, $reference), $actor)->entry;
        } catch (EntryRefused $refused) {
            $message = 'L’écriture ne peut pas être passée : '.$refused->getMessage();

            throw new BankingRefused($message, ['entry' => $message]);
        }
    }

    /**
     * The VAT on receipts an invoice left on 445800 (its credits less its debits there), never that of
     * an opening entry: the à-nouveaux carry the account's balance, not an invoice's.
     */
    private static function pendingVat(Entry $entry, PaymentDirection $direction): Money
    {
        if (PaymentDirection::Receipt !== $direction || JournalType::Opening === $entry->getJournal()->getType()) {
            return Money::zero();
        }
        $vat = Money::zero();
        foreach ($entry->getLines() as $line) {
            if (SalesEntries::VAT_ON_RECEIPTS === $line->getAccount()->getNumber()) {
                $vat = $vat->plus($line->getCredit())->minus($line->getDebit());
            }
        }

        return $vat->isPositive() ? $vat : Money::zero();
    }

    /** @return list<int> the payment's unlettered lines on the party's account, and its invoices' there not lettered whole */
    private function letteringLines(Payment $payment): array
    {
        $entry = $payment->getEntry() ?? throw new \LogicException('A payment lettered has its entry.');
        $ids = [];
        foreach ($this->partyLines($payment, $entry) as $line) {
            if (!$line->isLettered()) {
                $ids[] = (int) $line->getId();
            }
        }
        // An invoice another payment already lettered in part: its group is completed.
        foreach ($payment->getAllocations() as $allocation) {
            foreach ($this->partyLines($payment, $allocation->getInvoice()) as $line) {
                if (!$line->isLettered() || $line->isPartiallyLettered()) {
                    $ids[] = (int) $line->getId();
                }
            }
        }

        return $ids;
    }

    /** @return list<EntryLine> the entry's lines on the payment's third party's own account */
    private function partyLines(Payment $payment, Entry $entry): array
    {
        $party = $payment->getThirdParty();
        $account = $party->getAccount();

        return array_values(array_filter($entry->getLines(), static fn (EntryLine $line): bool => $line->getAccount() === $account && $line->getThirdParty() === $party));
    }

    /** The highest slip number the books already used (« REM-0007 », entered by hand before this module). */
    private function lastSlipInBooks(Dossier $dossier): int
    {
        /** @var list<string> $documents */
        $documents = $this->entityManager->createQueryBuilder()
            ->select('e.document')->from(Entry::class, 'e')
            ->andWhere('e.dossier = :dossier')->setParameter('dossier', $dossier)
            ->andWhere('e.document LIKE :slip')->setParameter('slip', 'REM-%')
            ->getQuery()->getSingleColumnResult();
        $last = 0;
        foreach ($documents as $document) {
            if (1 === preg_match('/^REM-(\d{1,6})$/', $document, $match)) {
                $last = max($last, (int) $match[1]);
            }
        }

        return $last;
    }
}
