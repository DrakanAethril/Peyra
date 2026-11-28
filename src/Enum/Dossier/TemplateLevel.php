<?php

declare(strict_types=1);

namespace App\Enum\Dossier;

/** The year of the BTS CG a template is written for (Bibliothèque › Dossiers modèles, « niveau visé »). */
enum TemplateLevel: string
{
    case First = 'first';
    case Second = 'second';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::First => '1re année',
            self::Second => '2e année',
            self::Both => '1re et 2e années',
        };
    }
}
