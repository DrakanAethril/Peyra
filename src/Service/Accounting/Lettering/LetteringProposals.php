<?php

declare(strict_types=1);

namespace App\Service\Accounting\Lettering;

/**
 * The automatic lettering (lot 1, step 1.6): groups of lines still unlettered of one account that
 * balance, found by amount or by document - a pure function. Lines already lettered, even partly,
 * are left to the person.
 *
 * - by amount: a debit and a credit of the same amount, paired in date order (the oldest debit
 *   with the oldest credit of that amount) - two invoices of 960,00 and two payments of 960,00
 *   make two pairs;
 * - by document: the lines that share their document reference (« pièce », case aside) when they
 *   hold debits and credits and balance - an invoice and its payment entered under its number, an
 *   entry and its reversal.
 *
 * Groups come in the order of their first line's date.
 */
final class LetteringProposals
{
    /**
     * @param list<LetteringLine> $lines
     *
     * @return list<list<int>>
     */
    public static function byAmount(array $lines): array
    {
        $debits = [];
        $credits = [];
        foreach (self::open($lines) as $line) {
            if ($line->debit > 0) {
                $debits[$line->debit][] = $line;
            } else {
                $credits[$line->credit][] = $line;
            }
        }

        $groups = [];
        foreach ($debits as $amount => $sameDebits) {
            $sameCredits = $credits[$amount] ?? [];
            $count = min(\count($sameDebits), \count($sameCredits));
            for ($index = 0; $index < $count; ++$index) {
                $groups[] = [$sameDebits[$index], $sameCredits[$index]];
            }
        }

        return self::ordered($groups);
    }

    /**
     * @param list<LetteringLine> $lines
     *
     * @return list<list<int>>
     */
    public static function byDocument(array $lines): array
    {
        $byDocument = [];
        foreach (self::open($lines) as $line) {
            $document = mb_strtoupper(trim($line->document));
            if ('' !== $document) {
                $byDocument[$document][] = $line;
            }
        }

        $groups = [];
        foreach ($byDocument as $group) {
            $hasDebit = [] !== array_filter($group, static fn (LetteringLine $line): bool => $line->debit > 0);
            $hasCredit = [] !== array_filter($group, static fn (LetteringLine $line): bool => $line->credit > 0);
            $balance = array_sum(array_map(static fn (LetteringLine $line): int => $line->amount(), $group));
            if ($hasDebit && $hasCredit && 0 === $balance) {
                $groups[] = $group;
            }
        }

        return self::ordered($groups);
    }

    /**
     * The unlettered lines in date order (then id).
     *
     * @param list<LetteringLine> $lines
     *
     * @return list<LetteringLine>
     */
    private static function open(array $lines): array
    {
        $open = array_values(array_filter($lines, static fn (LetteringLine $line): bool => null === $line->letter));
        usort($open, static fn (LetteringLine $a, LetteringLine $b): int => [$a->day, $a->id] <=> [$b->day, $b->id]);

        return $open;
    }

    /**
     * @param list<list<LetteringLine>> $groups
     *
     * @return list<list<int>>
     */
    private static function ordered(array $groups): array
    {
        usort($groups, static fn (array $a, array $b): int => [$a[0]->day, $a[0]->id] <=> [$b[0]->day, $b[0]->id]);

        return array_map(static function (array $group): array {
            $ids = array_map(static fn (LetteringLine $line): int => $line->id, $group);
            sort($ids);

            return $ids;
        }, $groups);
    }
}
