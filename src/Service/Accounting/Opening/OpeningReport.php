<?php

declare(strict_types=1);

namespace App\Service\Accounting\Opening;

use App\Entity\Accounting\Account;
use App\Entity\Accounting\Entry;
use App\Entity\Accounting\Journal;
use App\Entity\Dossier\Exercise;
use App\Entity\Dossier\Period;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;

/**
 * The opening balance of an exercise (lot 1, step 1.6): the à-nouveaux journal's entries of its
 * first day, by account, and what the controls say - balanced, validated, nothing typed left
 * unbalanced in the grid, balance-sheet accounts only.
 */
final readonly class OpeningReport
{
    /**
     * @param list<Entry>              $entries
     * @param list<OpeningAccountRow>  $rows
     * @param list<OpeningPending>     $pending
     * @param list<Account>            $resultAccounts accounts of classes 6 and 7 the à-nouveaux name
     */
    public function __construct(
        public Exercise $exercise,
        public ?Journal $journal,
        public ?Period $firstPeriod,
        public array $entries,
        public array $rows,
        public array $pending,
        public array $resultAccounts,
    ) {
    }

    public function debit(): Money
    {
        return Money::sum(array_map(static fn (OpeningAccountRow $row): Money => $row->debit, $this->rows));
    }

    public function credit(): Money
    {
        return Money::sum(array_map(static fn (OpeningAccountRow $row): Money => $row->credit, $this->rows));
    }

    public function drafts(): int
    {
        return \count(array_filter($this->entries, static fn (Entry $entry): bool => !$entry->isValidated()));
    }

    /** Debit minus credit of the opening balance, the typing left in the grid included. */
    public function gap(): Money
    {
        return Money::sum([$this->debit()->minus($this->credit()), ...array_map(static fn (OpeningPending $pending): Money => $pending->gap(), $this->pending)]);
    }

    /** The opening balance balances, each person's typing left in the grid included. */
    public function isBalanced(): bool
    {
        if (!$this->debit()->equals($this->credit())) {
            return false;
        }

        return [] === array_filter($this->pending, static fn (OpeningPending $pending): bool => !$pending->gap()->isZero());
    }

    /**
     * The controls, as Px:Checks reads them.
     *
     * @return list<array{state: string, text: string}>
     */
    public function checks(): array
    {
        if ([] === $this->entries && [] === $this->pending) {
            return [['state' => 'warning', 'text' => \sprintf('Aucun à-nouveau pour l’exercice %s : saisissez la balance d’ouverture dans le journal %s, datée du %s.', $this->exercise->getCode(), $this->journal?->getCode() ?? 'd’à-nouveaux', FrenchFormat::date($this->exercise->getStartsOn()))]];
        }

        $checks = [];
        if ([] !== $this->entries) {
            $checks[] = $this->debit()->equals($this->credit())
                ? ['state' => 'passed', 'text' => \sprintf('Les écritures d’à-nouveaux sont équilibrées : %s au débit et au crédit.', FrenchFormat::amount($this->debit()))]
                : ['state' => 'blocking', 'text' => \sprintf('Les écritures d’à-nouveaux ne sont pas équilibrées : %s au débit, %s au crédit.', FrenchFormat::amount($this->debit()), FrenchFormat::amount($this->credit()))];
        }
        foreach ($this->pending as $pending) {
            $gap = $pending->gap();
            $checks[] = [
                'state' => $gap->isZero() ? 'warning' : 'blocking',
                'text' => $gap->isZero()
                    ? \sprintf('Saisie en attente de %s : %d ligne%s équilibrée%s, pas encore au brouillard (F10 dans la grille).', $pending->author->getDisplayName(), $pending->lines, $pending->lines > 1 ? 's' : '', $pending->lines > 1 ? 's' : '')
                    : \sprintf('Saisie en attente de %s : %d ligne%s, écart de %s au %s. La balance d’ouverture ne tombe pas juste.', $pending->author->getDisplayName(), $pending->lines, $pending->lines > 1 ? 's' : '', FrenchFormat::amount($gap->abs()), $gap->isPositive() ? 'débit' : 'crédit'),
            ];
        }
        if ([] !== $this->entries) {
            $drafts = $this->drafts();
            $checks[] = 0 === $drafts
                ? ['state' => 'passed', 'text' => 'Les écritures d’à-nouveaux sont validées.']
                : ['state' => 'warning', 'text' => \sprintf('%d écriture%s d’à-nouveaux au brouillard : validez-la%s pour arrêter la balance d’ouverture.', $drafts, $drafts > 1 ? 's' : '', $drafts > 1 ? 's' : '')];
            $checks[] = [] === $this->resultAccounts
                ? ['state' => 'passed', 'text' => 'Seuls des comptes de bilan (classes 1 à 5) sont repris.']
                : ['state' => 'warning', 'text' => \sprintf('Les à-nouveaux reprennent %s : un compte de charges ou de produits se solde à la clôture, il ne se reprend pas.', implode(', ', array_map(static fn (Account $account): string => $account->getNumber(), $this->resultAccounts)))];
        }

        return $checks;
    }
}
