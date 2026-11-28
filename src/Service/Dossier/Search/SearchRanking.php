<?php

declare(strict_types=1);

namespace App\Service\Dossier\Search;

use App\Service\Accounting\Chart\AccountSearch;

/**
 * How well a screen or an action answers what is typed in the global search (Ctrl K), a pure
 * function: the smaller the better, null when it does not answer. A code typed whole (« ach » for
 * the journal ACH) comes first, then a code's start, then a label whose first word starts with the
 * first word typed, then a label or its terms whose words start with every word typed, then words
 * merely held - without accents nor case, as the account picker reads labels. « ach » then
 * « Entrée » opens the purchases' grid (design-ux.md § 4.4).
 */
final class SearchRanking
{
    public const int CODE = 0;
    public const int CODE_START = 1;
    public const int FIRST_WORD = 2;
    public const int WORD_STARTS = 3;
    public const int HELD = 4;

    public static function rank(string $query, string $label, string $terms = '', ?string $code = null): ?int
    {
        $words = self::words($query);
        if ([] === $words) {
            return null;
        }
        $code = null === $code ? '' : mb_strtolower($code);
        $compact = implode('', $words);
        if ('' !== $code && $code === $compact) {
            return self::CODE;
        }
        if ('' !== $code && 1 === \count($words) && str_starts_with($code, $compact)) {
            return self::CODE_START;
        }

        $labelWords = self::words($label);
        $allWords = [...$labelWords, ...self::words($terms)];
        $startsEvery = true;
        foreach ($words as $word) {
            if (!self::startsOne($word, $allWords)) {
                $startsEvery = false;
                break;
            }
        }
        if ($startsEvery) {
            return isset($labelWords[0]) && str_starts_with($labelWords[0], $words[0]) ? self::FIRST_WORD : self::WORD_STARTS;
        }

        $haystack = implode(' ', $allWords);
        foreach ($words as $word) {
            if (!str_contains($haystack, $word)) {
                return null;
            }
        }

        return self::HELD;
    }

    /** @return list<string> the folded words of a text */
    private static function words(string $text): array
    {
        return array_values(array_filter(explode(' ', AccountSearch::fold($text)), static fn (string $word): bool => '' !== $word));
    }

    /** @param list<string> $candidates */
    private static function startsOne(string $word, array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if (str_starts_with($candidate, $word)) {
                return true;
            }
        }

        return false;
    }
}
