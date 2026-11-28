<?php

declare(strict_types=1);

namespace App\Service\Document\Preparation;

use App\Service\Document\Generator\InvoiceCalculator;

/**
 * The three plausible wrong values the panel « Glisser une erreur » offers (design-ux.md § 3.5), a
 * pure function: for an amount, the HT taken for the TTC (or the reverse) and digits swapped - the
 * euros' units and tens, the cents, the euros' tens and hundreds, as the generator misprints them;
 * for a rate, the other rates in force at the dossier's date; for an account, its neighbours in the
 * plan - same two first digits first, then same class, nearest number first; for a third party, the
 * names nearest to its own.
 */
final class SlipSuggestions
{
    public const int COUNT = 3;

    /**
     * @param int|null $counterpart the HT when the amount is the TTC, the TTC when it is the HT
     *
     * @return list<int> cents
     */
    public static function amounts(int $right, ?int $counterpart): array
    {
        $candidates = [$counterpart, InvoiceCalculator::misprint($right, 2), InvoiceCalculator::misprint($right, 0), InvoiceCalculator::misprint($right, 3)];
        $kept = [];
        foreach ($candidates as $candidate) {
            if (null !== $candidate && $candidate > 0 && $candidate !== $right && !\in_array($candidate, $kept, true)) {
                $kept[] = $candidate;
            }
        }

        return \array_slice($kept, 0, self::COUNT);
    }

    /**
     * @param list<string> $inForce the rates in force, canonical (« 20 », « 5.5 »), in their order
     *
     * @return list<string>
     */
    public static function rates(string $right, array $inForce): array
    {
        return \array_slice(array_values(array_unique(array_filter($inForce, static fn (string $rate): bool => $rate !== $right))), 0, self::COUNT);
    }

    /**
     * @param list<string> $plan the dossier's account numbers
     *
     * @return list<string>
     */
    public static function accounts(string $right, array $plan): array
    {
        $near = [];
        foreach ($plan as $number) {
            if ($number === $right || $number[0] !== ($right[0] ?? '')) {
                continue;
            }
            $sameGroup = substr($number, 0, 2) === substr($right, 0, 2) ? 0 : 1;
            $near[] = [$sameGroup, abs((int) str_pad($number, 8, '0') - (int) str_pad($right, 8, '0')), $number];
        }
        usort($near, static fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

        return array_map(static fn (array $row): string => $row[2], \array_slice($near, 0, self::COUNT));
    }

    /**
     * @param array<string, string> $names the candidates' names by code, the right one included
     *
     * @return list<string> codes
     */
    public static function thirdParties(string $right, array $names, int $count = self::COUNT): array
    {
        $own = self::normalized($names[$right] ?? '');
        $near = [];
        foreach ($names as $code => $name) {
            $code = (string) $code;
            if ($code !== $right) {
                $near[] = [levenshtein($own, self::normalized($name)), $code];
            }
        }
        usort($near, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(static fn (array $row): string => $row[1], \array_slice($near, 0, $count));
    }

    private static function normalized(string $name): string
    {
        return mb_substr(mb_strtolower(trim($name)), 0, 100);
    }
}
