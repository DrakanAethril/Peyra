<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Identity\User;
use App\Entity\Regulatory\RegulatoryValue;
use App\Service\Regulatory\RegulatoryFileEntry;

/**
 * Regulatory values built in memory, as a millésime file or an administrator would write them.
 * In a file of its own: ParaTest hands out files, not classes.
 */
final class RegulatoryFixtures
{
    /** @param string|bool|array<array-key, mixed>|null $tree numbers as decimal texts (« 12.02 ») */
    public static function entry(string $key, string $effectiveFrom, string|bool|array|null $tree, string $unit = 'EUR', bool $verified = true, ?string $label = null, ?string $note = null): RegulatoryFileEntry
    {
        return new RegulatoryFileEntry(
            $key,
            $label ?? 'Libellé de '.$key,
            $tree,
            $unit,
            new \DateTimeImmutable($effectiveFrom),
            $verified ? 'https://www.urssaf.fr/accueil/outils-documentation/taux-baremes.html' : null,
            $verified ? new \DateTimeImmutable('2026-11-27') : null,
            $verified,
            $note,
        );
    }

    /** @param string|bool|array<array-key, mixed>|null $tree */
    public static function fromFile(string $key, string $effectiveFrom, string|bool|array|null $tree, int $millesime = 2026, string $unit = 'EUR', bool $verified = true, ?string $label = null): RegulatoryValue
    {
        return RegulatoryValue::fromFile(self::entry($key, $effectiveFrom, $tree, $unit, $verified, $label), $millesime, new \DateTimeImmutable('2026-11-27 10:00'));
    }

    /** @param string|bool|array<array-key, mixed>|null $tree */
    public static function byAdministrator(RegulatoryValue $base, string $effectiveFrom, string|bool|array|null $tree, User $administrator, bool $verified = false): RegulatoryValue
    {
        return RegulatoryValue::byAdministrator($base, $tree, new \DateTimeImmutable($effectiveFrom), null, null, $verified, null, $administrator, new \DateTimeImmutable('2026-11-28 09:30'));
    }
}
