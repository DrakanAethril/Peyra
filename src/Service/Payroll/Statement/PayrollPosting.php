<?php

declare(strict_types=1);

namespace App\Service\Payroll\Statement;

use App\Entity\Accounting\Entry;
use App\Entity\Accounting\Journal;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Accounting\AccountingBehaviour;
use App\Enum\Accounting\AccountingModule;
use App\Enum\Accounting\EntryOrigin;
use App\Enum\Accounting\JournalType;
use App\Enum\Payroll\PayrollBodyKind;
use App\Repository\Accounting\EntryRepository;
use App\Repository\Accounting\JournalRepository;
use App\Service\Accounting\Gateway\AccountingGateway;
use App\Service\Accounting\Gateway\EntryProposal;
use App\Service\Accounting\Ledger\EntryRefused;
use App\Service\Document\Processing\PieceCheck;
use App\Service\Document\Processing\PieceProposal;
use App\Service\Document\Processing\ProposedEntryCheck;
use App\Service\Payroll\Payslip\PayslipBook;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;

/**
 * How the payroll reaches the books (step 4.5; design-ux.md § 3.2 « Écritures de paie »): never by
 * itself - it hands its entries (PayrollEntries) to AccountingGateway, and the dossier's behaviour
 * decides, as for the sales (SalesPosting):
 *
 * - the month's entry exists once the month of pay is closed - its payslips no longer move: under
 *   « Comptabiliser » it is written in the brouillard at the closing, when the grid's checks let it;
 *   under « Proposer » the screen shows it and the student passes it to the brouillard; under
 *   « Laisser saisir » nothing is shown nor written, the student enters it in the general journal;
 * - a payment (the net salaries, a body's contributions) is passed to the brouillard from the screen,
 *   in the bank journal chosen, at the dossier's date;
 * - a payslip cancelled in a month whose entry was posted takes that entry back: deleted while in the
 *   brouillard, reversed once validated (design-ux.md § 4.7, point 6).
 *
 * The entries are found again by their pièce (« PAIE-2026-03 », « VIRSAL-2026-03 »…): a pièce still
 * standing (neither a reversal nor reversed) is not proposed again; only what the payroll wrote itself
 * (its module) is taken back. Read inside the dossier's context.
 */
final readonly class PayrollPosting
{
    public function __construct(
        private AccountingGateway $gateway,
        private JournalRepository $journals,
        private EntryRepository $entries,
        private ProposedEntryCheck $entryCheck,
        private PayslipBook $book,
    ) {
    }

    public function behaviour(Dossier $dossier): AccountingBehaviour
    {
        return $this->gateway->behaviourFor($dossier, AccountingModule::Payroll);
    }

    /** The general journal the month's entry goes to: the dossier's first « opérations diverses » one, by code. */
    public function journal(Dossier $dossier): ?Journal
    {
        return $this->journals->findByType($dossier, JournalType::General)[0] ?? null;
    }

    /** @return list<Journal> the bank journals a payment may go to */
    public function bankJournals(Dossier $dossier): array
    {
        return $this->journals->findByType($dossier, JournalType::Bank);
    }

    /** Whether the month of pay is closed: its payslips are final, its entry may be posted. */
    public function isClosed(Dossier $dossier, \DateTimeImmutable $month): bool
    {
        $last = $this->book->lastClosed($dossier);

        return null !== $last && $month <= $last->getMonth();
    }

    /** The month's entry, null for a month with no validated payslip or a dossier without a general journal. */
    public function proposal(Dossier $dossier, PayrollMonthStatements $statements): ?PieceProposal
    {
        $journal = $this->journal($dossier);

        return $statements->isEmpty() || null === $journal ? null : PayrollEntries::month($statements, $journal->getCode());
    }

    public function check(Dossier $dossier, PieceProposal $proposal): PieceCheck
    {
        return $this->entryCheck->check($dossier, $proposal);
    }

    /** @return list<Entry> every entry of that pièce, by date: what the screen lists, whoever wrote it */
    public function entries(Dossier $dossier, string $document): array
    {
        return $this->entries->findByDocumentReference($dossier, $document);
    }

    /**
     * The entries of that pièce still standing - neither a reversal nor reversed -, whoever wrote them: a
     * pièce written already, by the payroll or by hand, is not proposed again.
     *
     * @return list<Entry>
     */
    public function standing(Dossier $dossier, string $document): array
    {
        return array_values(array_filter(
            $this->entries($dossier, $document),
            fn (Entry $entry): bool => EntryOrigin::Reversal !== $entry->getOrigin() && null === $this->entries->findReversalOf($entry),
        ));
    }

    /**
     * Just closed (PayslipBook::close(), flushed): under « Comptabiliser », the month's entry goes to the
     * brouillard if the grid's checks let it - else it waits on Écritures de paie, as under « Proposer ».
     */
    public function monthClosed(Dossier $dossier, PayrollMonthStatements $statements, User|string $by): void
    {
        if (AccountingBehaviour::Post !== $this->behaviour($dossier) || [] !== $this->standing($dossier, PayrollEntries::monthDocument($statements->month))) {
            return;
        }
        $proposal = $this->proposal($dossier, $statements);
        $check = null === $proposal ? null : $this->check($dossier, $proposal);
        if (null === $proposal || null === $check || !$check->isWritable() || null === $check->grid) {
            return;
        }
        try {
            $this->gateway->submit($dossier, new EntryProposal(AccountingModule::Payroll, $check->grid->draft, $proposal->document), $by);
        } catch (EntryRefused) {
            // Refused all the same (a profile that may not enter the general journal): it waits on the screen.
        }
    }

    /**
     * « Passer l'écriture au brouillard »: the month's entry, its month closed, under « Proposer » - or
     * « Comptabiliser » when the closing could not write it. Refused, nothing is written and the check
     * says why.
     */
    public function post(Dossier $dossier, PayrollMonthStatements $statements, User $actor): PieceCheck
    {
        if (!$this->isClosed($dossier, $statements->month) || AccountingBehaviour::LetEnter === $this->behaviour($dossier)
            || [] !== $this->standing($dossier, PayrollEntries::monthDocument($statements->month))) {
            throw new \LogicException('This month’s entry is not posted from here.');
        }
        $proposal = $this->proposal($dossier, $statements) ?? throw new \LogicException('This month asks for no entry.');

        return $this->record($dossier, $proposal, $actor);
    }

    /**
     * A payslip of the month cancelled (PayslipBook::cancel()): the month's entry, if the payroll wrote
     * one, is taken back - deleted from the brouillard, or reversed at the dossier's date. Null when done
     * (or nothing to do), else why not; the cancellation then waits.
     */
    public function withdraw(Dossier $dossier, \DateTimeImmutable $month, User|string $by): ?string
    {
        foreach ($this->standing($dossier, PayrollEntries::monthDocument($month)) as $entry) {
            if (AccountingModule::Payroll->value !== $entry->getSource()) {
                continue; // Entered by hand: the student's to correct.
            }
            try {
                $this->gateway->withdraw($entry, $dossier->getDossierDate(), $by);
            } catch (EntryRefused $refused) {
                return 'L’écriture de paie du mois ne peut pas être contrepassée : '.implode(' ', array_unique(array_values($refused->errors)));
            }
        }

        return null;
    }

    /**
     * What the month leaves to pay: the net salaries (421000), then what each body is owed, then the
     * withholding tax paid back to the tax office with the DSN (step 4.6: the account the plan's tax
     * rubrique credits, 442100), each with the pièce of its payment.
     *
     * @return list<array{key: string, label: string, account: string, amount: Money, document: string}>
     */
    public function payments(PayrollMonthStatements $statements): array
    {
        $month = $statements->month;
        $period = $month->format('Y-m');
        $payments = [['key' => 'salaries', 'label' => 'Salaires nets '.FrenchFormat::ofPeriod($month), 'account' => PayrollAccounts::NET_PAY, 'amount' => $statements->totals()['netPay'], 'document' => 'VIRSAL-'.$period]];
        $kinds = [];
        foreach ($statements->bodies() as $state) {
            if (null !== $state['body']) {
                $kinds[$state['body']->getKind()->value] = ($kinds[$state['body']->getKind()->value] ?? 0) + 1;
            }
        }
        foreach ($statements->bodies() as $state) {
            $body = $state['body'];
            if (null === $body || !$state['total']->isPositive()) {
                continue;
            }
            $code = self::bodyCode($body->getKind()).($kinds[$body->getKind()->value] > 1 ? '-'.$state['key'] : '');
            $payments[] = ['key' => 'body-'.$state['key'], 'label' => $body->getName().' · cotisations '.FrenchFormat::ofPeriod($month), 'account' => $body->owedAccount(), 'amount' => $state['total'], 'document' => 'COT-'.$code.'-'.$period];
        }

        $payments[] = ['key' => 'withholding', 'label' => 'Prélèvement à la source '.FrenchFormat::ofPeriod($month), 'account' => $statements->withholdingAccount, 'amount' => $statements->totals()['withholding'], 'document' => 'PAS-'.$period];

        return array_values(array_filter($payments, static fn (array $payment): bool => $payment['amount']->isPositive()));
    }

    /** The payment's entry in that bank journal, at the dossier's date. */
    public function paymentProposal(Dossier $dossier, array $payment, Journal $bank): ?PieceProposal
    {
        $account = $bank->getCounterpartAccount()?->getNumber();

        return null === $account ? null : PayrollEntries::payment($payment['account'], $payment['amount'], $bank->getCode(), $account, $dossier->getDossierDate(), $payment['document'], $payment['label']);
    }

    /**
     * « Passer le virement au brouillard »: a payment of the month, through the gateway, unless one of the
     * same pièce stands already.
     *
     * @param array{key: string, label: string, account: string, amount: Money, document: string} $payment
     */
    public function pay(Dossier $dossier, array $payment, Journal $bank, User $actor): PieceCheck
    {
        if (AccountingBehaviour::LetEnter === $this->behaviour($dossier) || [] !== $this->standing($dossier, $payment['document'])) {
            throw new \LogicException('This payment is not posted from here.');
        }
        $proposal = $this->paymentProposal($dossier, $payment, $bank);
        if (null === $proposal) {
            return new PieceCheck(null, ['journal' => \sprintf('Le journal %s n’a pas de compte de banque.', $bank->getCode())]);
        }

        return $this->record($dossier, $proposal, $actor);
    }

    private function record(Dossier $dossier, PieceProposal $proposal, User $actor): PieceCheck
    {
        $check = $this->check($dossier, $proposal);
        if (!$check->isWritable() || null === $check->grid) {
            return $check;
        }
        try {
            $this->gateway->record($dossier, new EntryProposal(AccountingModule::Payroll, $check->grid->draft, $proposal->document), $actor);
        } catch (EntryRefused $refused) {
            $check->errors = [...$check->errors, ...$refused->errors];

            return $check;
        }
        $check->posted = true;

        return $check;
    }

    private static function bodyCode(PayrollBodyKind $kind): string
    {
        return match ($kind) {
            PayrollBodyKind::Urssaf => 'URSSAF',
            PayrollBodyKind::Retirement => 'RETRAITE',
            PayrollBodyKind::Provident => 'PREVOYANCE',
            PayrollBodyKind::Health => 'MUTUELLE',
        };
    }
}
