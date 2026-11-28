<?php

declare(strict_types=1);

namespace App\Enum\Dossier;

/**
 * Why a restore point exists: posed by a person, or posed by Peyra before a return to another point
 * (« Poser d'abord un point de l'état actuel », design-ux.md § 4.9), or posed by a distribution at
 * the end of each copy: « État de départ », what the teacher's reset goes back to (lot 1, step
 * 1.8). The latter is outside the limit of ten points, and only a teacher deletes it.
 */
enum RestorePointOrigin: string
{
    case Manual = 'manual';
    case BeforeReturn = 'before_return';
    case Start = 'start';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Posé à la main',
            self::BeforeReturn => 'Posé avant un retour',
            self::Start => 'Posé à la distribution',
        };
    }

    /** Counted against the ten points a dossier keeps. */
    public function countsAgainstLimit(): bool
    {
        return self::Start !== $this;
    }
}
