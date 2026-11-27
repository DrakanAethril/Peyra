<?php

declare(strict_types=1);

namespace App\Service\Accounting\Chart;

/**
 * A reference chart of accounts (« PCG 2026 »), read from `fixtures/pcg/` by ReferenceCharts: the
 * ANC's list, its source, and the exercises it applies to. A dossier's plan is a copy of the
 * accounts of three digits and more (padded to six), which the dossier then extends; the
 * one- and two-digit levels stay headings (« 60 · Achats »).
 */
final readonly class ReferenceChart
{
    /** @var array<string, ReferenceAccount> by number */
    private array $byNumber;

    /**
     * @param list<ReferenceAccount> $accounts     by number, as text
     * @param array<int, string>     $classLabels  1 => « Comptes de capitaux »…
     */
    public function __construct(
        public string $code,
        public string $label,
        public string $regulation,
        public ?\DateTimeImmutable $appliesFrom,
        public string $source,
        public \DateTimeImmutable $consulted,
        public bool $verified,
        public string $note,
        public array $accounts,
        private array $classLabels,
    ) {
        $byNumber = [];
        foreach ($accounts as $account) {
            $byNumber[$account->number] = $account;
        }
        $this->byNumber = $byNumber;
    }

    public function find(string $number): ?ReferenceAccount
    {
        return $this->byNumber[$number] ?? null;
    }

    /**
     * The deepest account of the chart a number subdivides (« Chaque compte à deux chiffres et plus
     * peut être subdivisé », PCG art. 1121-2): « 601 » for 601300, « 58 » for 580000, null when the
     * number subdivides none (it is outside the plan).
     */
    public function rootOf(string $number): ?ReferenceAccount
    {
        for ($length = min(\strlen($number), 6); $length >= 2; --$length) {
            $account = $this->byNumber[substr($number, 0, $length)] ?? null;
            if (null !== $account) {
                return $account;
            }
        }

        return null;
    }

    /** The two-digit heading a number falls under (« 60 », « Achats »), or null. */
    public function groupOf(string $number): ?ReferenceAccount
    {
        return $this->byNumber[substr($number, 0, 2)] ?? null;
    }

    /** « Comptes de charges » for 6. */
    public function classLabel(int $digit): string
    {
        return $this->classLabels[$digit] ?? 'Classe '.$digit;
    }

    /** @return array<int, string> the eight classes, 1 => « Comptes de capitaux »… */
    public function classLabels(): array
    {
        return $this->classLabels;
    }

    /** @return list<ReferenceAccount> what a dossier receives: the accounts of three digits and more */
    public function copiedAccounts(): array
    {
        return array_values(array_filter($this->accounts, static fn (ReferenceAccount $account): bool => \strlen($account->number) >= 3));
    }

    /** Whether it may apply to an exercise starting that day (ReferenceCharts takes the latest that does). */
    public function appliesToExerciseStartingOn(\DateTimeImmutable $start): bool
    {
        return null === $this->appliesFrom || $start->format('Y-m-d') >= $this->appliesFrom->format('Y-m-d');
    }
}
