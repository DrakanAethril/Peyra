<?php

declare(strict_types=1);

namespace App\Service\Shared;

/**
 * « Du solde au détail, et retour » (lot 1, step 1.5): a screen reached from another one inside the
 * dossier is given that screen's address (`?return=`) and offers a link back. Only a path of the
 * same dossier is kept - « /d/12/accounting/… », no scheme, no host, no « // » - so the parameter
 * can never send a person elsewhere.
 */
final class ReturnPath
{
    private const int MAX_LENGTH = 1000;

    public static function of(string $typed, int $dossierId): ?string
    {
        $path = trim($typed);
        $prefix = '/d/'.$dossierId.'/';
        if ('' === $path || \strlen($path) > self::MAX_LENGTH || !str_starts_with($path, $prefix)) {
            return null;
        }
        if (str_contains($path, '//') || str_contains($path, '\\') || 1 === preg_match('/[\x00-\x1F\x7F]/', $path)) {
            return null;
        }

        return $path;
    }

    /** The address of the request, without its own `return` (a way back never nests). */
    public static function here(string $path, string $queryString): string
    {
        parse_str($queryString, $query);
        unset($query['return']);
        $rest = http_build_query($query);

        return '' === $rest ? $path : $path.'?'.$rest;
    }
}
