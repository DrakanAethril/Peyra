<?php

declare(strict_types=1);

namespace App\Service\Accounting\Consultation;

use App\Service\Accounting\Ledger\EntryScope;
use App\Service\Accounting\Ledger\LedgerMovement;
use App\Service\Accounting\Ledger\Totals;
use App\Service\Shared\ListPage;
use App\Service\Shared\Money;

/**
 * The consultation of an account or a third party over a window (design-ux.md § 3.2): what it
 * carried before the window in the window's exercise (`opening`, read on the day before `from`),
 * its movements with their running balance, the totals of the window, the balance at its end. A
 * page carries the balance before its first row, so every page opens on its report (§ 6.6).
 */
final readonly class AccountStatement
{
    /** @param list<LedgerMovement> $movements every movement of the window, balances included */
    public function __construct(
        public ConsultedSubject $subject,
        public ConsultationWindow $window,
        public EntryScope $scope,
        public Totals $opening,
        public bool $hasOpening,
        public array $movements,
    ) {
    }

    /** The debits and credits of the window, the opening apart. */
    public function totals(): Totals
    {
        $debit = Money::zero();
        $credit = Money::zero();
        foreach ($this->movements as $movement) {
            $debit = $debit->plus($movement->line->getDebit());
            $credit = $credit->plus($movement->line->getCredit());
        }

        return new Totals($debit, $credit);
    }

    /** The balance at the window's end (debit minus credit), the opening included. */
    public function closing(): Money
    {
        return $this->opening->balance()->plus($this->totals()->balance());
    }

    /** The day the opening is read on: the eve of the window. */
    public function openingDay(): \DateTimeImmutable
    {
        return $this->window->from->modify('-1 day');
    }

    /** @return array{rows: list<LedgerMovement>, carried: Money} the page's movements, and the balance before its first one */
    public function page(ListPage $page): array
    {
        $rows = \array_slice($this->movements, $page->offset(), $page->perPage);
        $before = $page->offset() > 0 ? $this->movements[$page->offset() - 1]->balanceAfter : $this->opening->balance();

        return ['rows' => $rows, 'carried' => $before];
    }
}
