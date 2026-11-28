<?php

declare(strict_types=1);

namespace App\Service\Dossier\Restore;

use App\Entity\Dossier\RestorePoint;

/**
 * The rules of the restore points (design-ux.md § 4.9), a pure function of what they are given:
 * the name a point takes, the name Peyra gives the point of the state it leaves, and the limit of
 * ten points a dossier keeps.
 */
final class RestorePointRules
{
    /** Points a dossier keeps; beyond, one is deleted before another is posed. */
    public const int LIMIT = 10;

    public const string LIMIT_REACHED = 'Ce dossier a déjà 10 points de restauration. Supprimez un point pour en poser un nouveau.';

    /** The name the field offers (design-ux.md § 4.9: « Avant validation du 30/11/2026 »), at the dossier's date. */
    public static function defaultName(\DateTimeImmutable $dossierDate): string
    {
        return 'Avant validation du '.$dossierDate->format('d/m/Y');
    }

    /** The name of the point posed before a return, « Avant le retour à “…” », cut to the column. */
    public static function safetyName(string $pointName): string
    {
        $head = 'Avant le retour à « ';
        $room = RestorePoint::NAME_MAX - mb_strlen($head) - 2;
        $name = mb_strlen($pointName) > $room ? rtrim(mb_substr($pointName, 0, $room - 1)).'…' : $pointName;

        return $head.$name.' »';
    }

    /** The sentence that refuses a name, or null when it is one. */
    public static function nameFault(string $name): ?string
    {
        $name = trim($name);
        if ('' === $name) {
            return 'Donnez un nom au point de restauration.';
        }
        if (mb_strlen($name) > RestorePoint::NAME_MAX) {
            return \sprintf('Le nom tient en %d caractères au plus.', RestorePoint::NAME_MAX);
        }

        return null;
    }

    public static function limitReached(int $points): bool
    {
        return $points >= self::LIMIT;
    }
}
