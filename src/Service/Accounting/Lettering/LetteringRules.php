<?php

declare(strict_types=1);

namespace App\Service\Accounting\Lettering;

use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;

/**
 * What a lettering decides (lot 1, step 1.6; design-ux.md § 3.2, « Lettrage ») - a pure function
 * over the lines of one account (or of one third party on a collective), which Lettering asks
 * after it has checked the account, the validation and the exercise:
 *
 * - a line already in a group that balances (capitals) is délettrée first;
 * - a line in a partial group (small letters) brings its whole group: the lettering completes it;
 * - debits and credits both: a lettering pairs what is owed with what settles it;
 * - the selection balances, or the lettering is partial - permitted at levels A and 0 only (G and
 *   B ask a selection that balances);
 * - the letter is the smallest partial letter the selection takes over, else the one after the
 *   highest the account used; capitals when it balances, small letters when partial.
 */
final class LetteringRules
{
    /**
     * @param list<LetteringLine> $selected        the lines chosen
     * @param list<LetteringLine> $account         every validated line of the account (its letters, its partial groups)
     * @param bool                $partialAllowed  levels A and 0
     */
    public static function decide(array $selected, array $account, bool $partialAllowed): LetteringDecision
    {
        if ([] === $selected) {
            return LetteringDecision::refused('Choisissez les lignes à lettrer : des débits et les crédits qui les soldent.');
        }

        $merged = [];
        foreach ($selected as $line) {
            if (null === $line->letter) {
                continue;
            }
            if (!LetterSequence::isPartial($line->letter)) {
                return LetteringDecision::refused(\sprintf('Une ligne choisie est déjà lettrée %s : délettrez-la d’abord.', $line->letter));
            }
            $merged[mb_strtoupper($line->letter)] = $line->letter;
        }

        /** @var array<int, LetteringLine> $lines */
        $lines = [];
        foreach ($selected as $line) {
            $lines[$line->id] = $line;
        }
        foreach ($account as $line) {
            if (null !== $line->letter && isset($merged[mb_strtoupper($line->letter)])) {
                $lines[$line->id] = $line;
            }
        }

        $debits = array_filter($lines, static fn (LetteringLine $line): bool => $line->debit > 0);
        $credits = array_filter($lines, static fn (LetteringLine $line): bool => $line->credit > 0);
        if ([] === $debits || [] === $credits) {
            return LetteringDecision::refused('Un lettrage rapproche des débits et des crédits : choisissez des lignes des deux côtés.');
        }

        if (1 === \count($merged)) {
            $only = array_key_first($merged);
            $group = array_filter($account, static fn (LetteringLine $line): bool => null !== $line->letter && LetterSequence::same($line->letter, $only));
            if (\count($group) === \count($lines)) {
                return LetteringDecision::refused(\sprintf('Ces lignes forment déjà le lettrage partiel %s : ajoutez celles qui le soldent.', $merged[$only]));
            }
        }

        $gap = array_sum(array_map(static fn (LetteringLine $line): int => $line->amount(), $lines));
        if (0 !== $gap && !$partialAllowed) {
            return LetteringDecision::refused(\sprintf(
                'La sélection n’est pas soldée : écart de %s au %s. Ajoutez les lignes qui la soldent.',
                FrenchFormat::amount(Money::cents(abs($gap))),
                $gap > 0 ? 'débit' : 'crédit',
            ));
        }

        if ([] !== $merged) {
            $ranks = array_map(static fn (string $letter): int => LetterSequence::ordinal($letter), array_keys($merged));
            $letter = LetterSequence::letter(min($ranks));
        } else {
            $used = [];
            foreach ($account as $line) {
                if (null !== $line->letter) {
                    $used[] = $line->letter;
                }
            }
            $letter = LetterSequence::next($used);
        }

        $ids = array_keys($lines);
        sort($ids);

        return LetteringDecision::letter(
            $ids,
            0 === $gap ? $letter : mb_strtolower($letter),
            $gap,
            array_values($merged),
        );
    }
}
