<?php

declare(strict_types=1);

namespace App\Service\Accounting\Lettering;

use App\Entity\Accounting\Account;
use App\Entity\Accounting\EntryLine;
use App\Service\Shared\Money;

/**
 * The face-à-face of an account (design-ux.md § 3.1, gabarit X): its validated debits on the left,
 * its credits on the right - the open ones (unlettered, or in a partial group), or all of them -,
 * with what the head counts.
 */
final readonly class LetteringView
{
    /**
     * @param list<string>    $usedLetters every letter the account carries, in capitals
     * @param list<EntryLine> $debits
     * @param list<EntryLine> $credits
     * @param list<Account>   $accounts the lettrable accounts a third party's validated lines sit on (one, often)
     */
    public function __construct(
        public LetteringKey $key,
        public array $usedLetters,
        public array $debits,
        public array $credits,
        public Money $balance,
        public Money $openDebit,
        public Money $openCredit,
        public int $openLines,
        public int $letteredGroups,
        public int $partialGroups,
        public int $drafts,
        public array $accounts,
    ) {
    }

    public function openBalance(): Money
    {
        return $this->openDebit->minus($this->openCredit);
    }

    /** What the debits shown add up to. */
    public function debitTotal(): Money
    {
        return Money::sum(array_map(static fn (EntryLine $line): Money => $line->getDebit(), $this->debits));
    }

    public function creditTotal(): Money
    {
        return Money::sum(array_map(static fn (EntryLine $line): Money => $line->getCredit(), $this->credits));
    }

    /** Whether a group of the account carries this letter (whatever its case). */
    public function holdsLetter(string $letter): bool
    {
        return '' !== $letter && \in_array(mb_strtoupper($letter), $this->usedLetters, true);
    }

    /**
     * The lines shown that carry their group's « Délettrer »: the first of each letter, debits first.
     *
     * @return array<int, true>
     */
    public function unletterLines(): array
    {
        $seen = [];
        $lines = [];
        foreach ([...$this->debits, ...$this->credits] as $line) {
            $letter = $line->getLetter();
            if (null !== $letter && !isset($seen[mb_strtoupper($letter)])) {
                $seen[mb_strtoupper($letter)] = true;
                $lines[(int) $line->getId()] = true;
            }
        }

        return $lines;
    }
}
