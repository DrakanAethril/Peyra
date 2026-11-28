<?php

declare(strict_types=1);

namespace App\Enum\Dossier;

/** Where a template sits for the teacher reading the library (design-ux.md § 2.6, « mes modèles · partagés avec moi · fournis »). */
enum TemplateShelf: string
{
    case Mine = 'mine';
    case Shared = 'shared';
    case Common = 'common';

    public function label(): string
    {
        return match ($this) {
            self::Mine => 'Mes modèles',
            self::Shared => 'Partagés avec moi',
            self::Common => 'Fournis',
        };
    }
}
