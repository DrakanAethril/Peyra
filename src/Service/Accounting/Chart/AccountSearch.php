<?php

declare(strict_types=1);

namespace App\Service\Accounting\Chart;

/**
 * Finds accounts the way the account picker (F4) and the plan's search box do (design-ui.md
 * § 4.6): digits look for the start of a number, words for the label - every word, without
 * accents nor case. A pure function over the plan, small enough to be read whole.
 */
final class AccountSearch
{
    /**
     * @template T
     *
     * @param list<T>                                     $accounts by number
     * @param callable(T): array{number: string, label: string} $read
     *
     * @return list<T> the accounts that match, by number, at most `$limit` (0: all)
     */
    public static function filter(array $accounts, callable $read, string $query, int $limit = 0): array
    {
        $query = trim($query);
        if ('' === $query) {
            return 0 === $limit ? $accounts : \array_slice($accounts, 0, $limit);
        }
        $compact = (string) preg_replace('/\s+/u', '', $query);
        $byNumber = 1 === preg_match('/^\d+$/', $compact);
        $words = $byNumber ? [] : self::words($query);
        $found = [];
        foreach ($accounts as $account) {
            ['number' => $number, 'label' => $label] = $read($account);
            if ($byNumber ? str_starts_with($number, $compact) : self::matchesWords($number, $label, $words)) {
                $found[] = $account;
                if (0 !== $limit && \count($found) >= $limit) {
                    break;
                }
            }
        }

        return $found;
    }

    /** Lower case without accents nor punctuation: « Opérations diverses » → « operations diverses ». */
    public static function fold(string $text): string
    {
        static $transliterator = null;
        $transliterator ??= \Transliterator::create('Any-Latin; Latin-ASCII; Lower()');
        $ascii = $transliterator?->transliterate($text);
        $ascii = \is_string($ascii) ? $ascii : mb_strtolower($text);

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $ascii));
    }

    /** @return list<string> */
    private static function words(string $query): array
    {
        return array_values(array_filter(explode(' ', self::fold($query)), static fn (string $word): bool => '' !== $word));
    }

    /** @param list<string> $words */
    private static function matchesWords(string $number, string $label, array $words): bool
    {
        if ([] === $words) {
            return false;
        }
        $haystack = self::fold($label);
        foreach ($words as $word) {
            if (!str_starts_with($number, $word) && !str_contains($haystack, $word)) {
                return false;
            }
        }

        return true;
    }
}
