<?php

declare(strict_types=1);

namespace App\Service\Banking\Reconciliation;

use App\Service\Shared\Money;

/**
 * The « état de rapprochement » of a statement (design-ux.md § 4.5, mock-up 2): the account's
 * balance corrected by the bank's operations the books do not hold yet (« à comptabiliser »: this
 * statement's lines left unpointed, and those earlier statements left), the bank's balance
 * corrected by the books' movements the bank has not passed yet (« non débités », « non
 * crédités »); the two corrected balances meet when the reconciliation falls right.
 */
final readonly class ReconciliationReport
{
    /**
     * @param list<ReconciliationItem> $toPost      this statement's lines that no pointing holds
     * @param list<ReconciliationItem> $outstanding the books' movements that no pointing holds
     * @param list<string>             $brokenLetters pointings whose two sides no longer meet (a draft changed or deleted)
     */
    public function __construct(
        public Money $accountBalance,
        public Money $closingBalance,
        public array $toPost,
        public Money $earlierToPost,
        public array $outstanding,
        public array $brokenLetters,
        public int $pointedLines,
        public int $statementLines,
    ) {
    }

    public function toPostTotal(): Money
    {
        return self::total($this->toPost);
    }

    public function outstandingTotal(): Money
    {
        return self::total($this->outstanding);
    }

    /** @return list<ReconciliationItem> the books' payments the bank has not debited yet (« chèques émis non débités ») */
    public function notDebited(): array
    {
        return array_values(array_filter($this->outstanding, static fn (ReconciliationItem $item): bool => $item->amount->isNegative()));
    }

    /** @return list<ReconciliationItem> the books' receipts the bank has not credited yet (« remises non créditées ») */
    public function notCredited(): array
    {
        return array_values(array_filter($this->outstanding, static fn (ReconciliationItem $item): bool => !$item->amount->isNegative()));
    }

    /** @param list<ReconciliationItem> $items */
    public static function total(array $items): Money
    {
        return Money::sum(array_map(static fn (ReconciliationItem $item): Money => $item->amount, $items));
    }

    /** « Solde rectifié en comptabilité ». */
    public function rectifiedBooks(): Money
    {
        return $this->accountBalance->plus($this->toPostTotal())->plus($this->earlierToPost);
    }

    /** « Solde rectifié du relevé ». */
    public function rectifiedBank(): Money
    {
        return $this->closingBalance->plus($this->outstandingTotal());
    }

    /** « Écart après justification ». */
    public function gap(): Money
    {
        return $this->rectifiedBooks()->minus($this->rectifiedBank());
    }

    /** The lines and movements still to deal with (mock-up 2, « Lignes à traiter »). */
    public function toDeal(): int
    {
        return \count($this->toPost) + \count($this->outstanding);
    }

    public function isPointed(ReconciliationItem $item): bool
    {
        return null !== $item->letter && !\in_array($item->letter, $this->brokenLetters, true);
    }
}
