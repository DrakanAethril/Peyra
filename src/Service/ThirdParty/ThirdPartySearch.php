<?php

declare(strict_types=1);

namespace App\Service\ThirdParty;

use App\Service\Accounting\Chart\AccountSearch;

/**
 * Finds third parties the way the account picker (F4) and the list's search box do: each word typed
 * is the start of the code or of the auxiliary code (« 401SC », « C00 »), or a part of the name -
 * without accents nor case. A pure function over the list, read whole.
 */
final class ThirdPartySearch
{
    /**
     * @template T
     *
     * @param list<T>                                                            $thirdParties by code
     * @param callable(T): array{code: string, auxiliary: ?string, name: string} $read
     *
     * @return list<T> the matching ones, in the order given, at most `$limit` (0: all)
     */
    public static function filter(array $thirdParties, callable $read, string $query, int $limit = 0): array
    {
        $words = array_values(array_filter(explode(' ', AccountSearch::fold($query)), static fn (string $word): bool => '' !== $word));
        if ([] === $words) {
            return 0 === $limit ? $thirdParties : \array_slice($thirdParties, 0, $limit);
        }
        $found = [];
        foreach ($thirdParties as $thirdParty) {
            ['code' => $code, 'auxiliary' => $auxiliary, 'name' => $name] = $read($thirdParty);
            $codes = [strtolower($code), strtolower($auxiliary ?? '')];
            $haystack = AccountSearch::fold($name);
            foreach ($words as $word) {
                if (!str_starts_with($codes[0], $word) && ('' === $codes[1] || !str_starts_with($codes[1], $word)) && !str_contains($haystack, $word)) {
                    continue 2;
                }
            }
            $found[] = $thirdParty;
            if (0 !== $limit && \count($found) >= $limit) {
                break;
            }
        }

        return $found;
    }
}
