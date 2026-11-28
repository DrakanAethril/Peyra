<?php

declare(strict_types=1);

namespace App\Enum\Dossier;

/**
 * Why a restore point exists: posed by a person, or posed by Peyra before a return to another point
 * (« Poser d'abord un point de l'état actuel », design-ux.md § 4.9). The distribution's « État de
 * départ » joins at step 1.8c.
 */
enum RestorePointOrigin: string
{
    case Manual = 'manual';
    case BeforeReturn = 'before_return';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Posé à la main',
            self::BeforeReturn => 'Posé avant un retour',
        };
    }
}
