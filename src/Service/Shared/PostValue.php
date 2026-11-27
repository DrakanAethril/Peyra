<?php

declare(strict_types=1);

namespace App\Service\Shared;

use Symfony\Component\HttpFoundation\Request;

/**
 * Reads a field of a posted form (not a Symfony form: a plain HTML one) as the type the caller
 * needs, anything unusable reading as « not provided » - the same rules as QueryValue, on the
 * request body. InputBag::all() and getInt() answer a 400 to a value of the wrong shape; a forged
 * or half-filled form is not worth one.
 */
final class PostValue
{
    public static function trimmed(Request $request, string $key, string $default = ''): string
    {
        $value = self::raw($request, $key);

        return \is_scalar($value) ? trim((string) $value) : $default;
    }

    public static function nullableInt(Request $request, string $key): ?int
    {
        $value = self::raw($request, $key);

        return \is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * An amount in cents as Px:AmountInput posts it (« 8000000 », « −13800 » as « -13800 »), zero
     * included; anything else, an empty field included, reads as null.
     */
    public static function cents(Request $request, string $key): ?int
    {
        $value = self::raw($request, $key);

        return \is_string($value) && 1 === preg_match('/^-?\d{1,15}$/', $value) ? (int) $value : null;
    }

    /**
     * A repeated field (`students[]=3&students[]=7`) as a list of ids, zero and junk dropped.
     *
     * @return list<int>
     */
    public static function intList(Request $request, string $key): array
    {
        $value = self::raw($request, $key);
        if (!\is_array($value)) {
            $value = [$value];
        }
        $ids = [];
        foreach ($value as $entry) {
            if (\is_string($entry) && ctype_digit($entry) && (int) $entry > 0) {
                $ids[] = (int) $entry;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * A field keyed by id (`other[12]=none`) as strings by id, junk keys and values dropped.
     *
     * @return array<int, string>
     */
    public static function stringsById(Request $request, string $key): array
    {
        return self::byId(self::raw($request, $key));
    }

    /**
     * A field keyed by name (`group[cg-1]=3`) as strings by key, non-scalar values dropped.
     *
     * @return array<string, string>
     */
    public static function stringsByKey(Request $request, string $key): array
    {
        $value = self::raw($request, $key);
        if (!\is_array($value)) {
            return [];
        }
        $strings = [];
        foreach ($value as $name => $entry) {
            if (\is_scalar($entry)) {
                $strings[(string) $name] = trim((string) $entry);
            }
        }

        return $strings;
    }

    /**
     * The same reading off the query string, for the GET forms that carry a table of choices.
     *
     * @return array<int, string>
     */
    public static function queryStringsById(Request $request, string $key): array
    {
        return self::byId($request->query->all()[$key] ?? null);
    }

    /** @return array<int, string> */
    private static function byId(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }
        $strings = [];
        foreach ($value as $id => $entry) {
            if (\is_int($id) && $id > 0 && \is_scalar($entry)) {
                $strings[$id] = trim((string) $entry);
            }
        }

        return $strings;
    }

    private static function raw(Request $request, string $key): mixed
    {
        return $request->request->all()[$key] ?? null;
    }
}
