<?php

declare(strict_types=1);

namespace App\Service\Accounting\Edition;

/**
 * A balance over a window - the general one (grouped by class), the third parties' (grouped by
 * kind): its groups, and the total of all. `hasOpening` when the window starts after its exercise
 * opened: the balance then shows what each line carried the eve.
 */
final readonly class BalanceReport
{
    /** @param list<BalanceGroup> $groups */
    public function __construct(
        public array $groups,
        public BalanceLine $total,
        public bool $hasOpening,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->groups;
    }

    public function lineCount(): int
    {
        return array_sum(array_map(static fn (BalanceGroup $group): int => \count($group->lines), $this->groups));
    }

    /**
     * The groups with their lines, built in order.
     *
     * @param array<string, list<BalanceLine>> $lines by group title, in the order shown
     */
    public static function of(array $lines, bool $hasOpening): self
    {
        $groups = [];
        $total = BalanceLine::total();
        foreach ($lines as $title => $groupLines) {
            if ([] === $groupLines) {
                continue;
            }
            $subtotal = BalanceLine::total();
            foreach ($groupLines as $line) {
                $subtotal = $subtotal->plus($line);
            }
            $groups[] = new BalanceGroup((string) $title, $groupLines, $subtotal);
            $total = $total->plus($subtotal);
        }

        return new self($groups, $total, $hasOpening);
    }
}
