<?php

declare(strict_types=1);

namespace App\Service\Banking\Reconciliation;

use App\Entity\Accounting\Account;
use App\Entity\Banking\BankRule;
use App\Entity\Banking\BankStatement;
use App\Entity\Banking\BankStatementLine;
use App\Enum\Banking\BankStatementStatus;
use App\Service\Shared\Money;

/**
 * What the reconciliation screen of a statement draws (mock-up 2): the statement's lines, the
 * account's movements it may point, the état de rapprochement, the pairs proposed at level G and
 * the assignment rule each open line falls under (G and B).
 */
final readonly class ReconciliationView
{
    /**
     * @param list<BankStatementLine>     $lines
     * @param list<BookItem>              $books
     * @param array<int, string>          $proposedLines   statement line id => proposed letter
     * @param array<int, string>          $proposedEntries entry id => proposed letter
     * @param list<array{statement: int, entry: int}> $proposals
     * @param array<int, BankRule>        $rules by statement line id
     */
    public function __construct(
        public BankStatement $statement,
        public Account $account,
        public ?BankStatement $previous,
        public ?BankStatement $next,
        public array $lines,
        public array $books,
        public ReconciliationReport $report,
        public array $proposals,
        public array $proposedLines,
        public array $proposedEntries,
        public array $rules,
        public ?string $openingNote,
    ) {
    }

    public function status(): BankStatementStatus
    {
        if ($this->statement->isReconciled()) {
            return BankStatementStatus::Reconciled;
        }

        return 0 === $this->report->pointedLines && [] === array_filter($this->books, static fn (BookItem $item): bool => null !== $item->letter) ? BankStatementStatus::ToReconcile : BankStatementStatus::InProgress;
    }

    /** The line's state: its letter while its pointing holds, else to be posted. */
    public function lineLetter(BankStatementLine $line): ?string
    {
        $letter = $line->getLetter();

        return null === $letter || \in_array($letter, $this->report->brokenLetters, true) ? null : $letter;
    }

    public function bookLetter(BookItem $item): ?string
    {
        return null === $item->letter || \in_array($item->letter, $this->report->brokenLetters, true) ? null : $item->letter;
    }

    public function isBroken(?string $letter): bool
    {
        return null !== $letter && \in_array($letter, $this->report->brokenLetters, true);
    }

    /**
     * The letters this statement uses, for the next one.
     *
     * @return list<string>
     */
    public function usedLetters(): array
    {
        $letters = array_map(static fn (BankStatementLine $line): ?string => $line->getLetter(), $this->lines);
        foreach ($this->books as $item) {
            $letters[] = $item->letter;
        }

        return array_values(array_unique(array_filter($letters, static fn (?string $letter): bool => null !== $letter)));
    }

    public function line(int $id): ?BankStatementLine
    {
        foreach ($this->lines as $line) {
            if ($line->getId() === $id) {
                return $line;
            }
        }

        return null;
    }

    /** Debits and credits of the statement's lines, as the bank prints them. */
    public function bankDebit(BankStatementLine $line): Money
    {
        return $line->getAmount()->isNegative() ? $line->getAmount()->abs() : Money::zero();
    }

    public function bankCredit(BankStatementLine $line): Money
    {
        return $line->getAmount()->isPositive() ? $line->getAmount() : Money::zero();
    }
}
