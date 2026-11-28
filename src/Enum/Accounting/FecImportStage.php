<?php

declare(strict_types=1);

namespace App\Enum\Accounting;

/**
 * The passes of a FEC's background import (step 1.10b), in their order: the plan, journals and
 * third parties in one pass, then the entries drafted, validated and lettered a batch per pass.
 */
enum FecImportStage: string
{
    case Setup = 'setup';
    case Drafts = 'drafts';
    case Validation = 'validation';
    case Lettering = 'lettering';

    public function label(): string
    {
        return match ($this) {
            self::Setup => 'Plan de comptes, journaux et tiers',
            self::Drafts => 'Écritures écrites',
            self::Validation => 'Écritures validées',
            self::Lettering => 'Lettrages',
        };
    }
}
