<?php

declare(strict_types=1);

namespace App\Service\Accounting\Entry;

use App\Entity\Dossier\Dossier;
use App\Enum\Accounting\AssistanceLevel;

/** Without a mission (lot 2 brings them), every dossier is worked at « Avertissements » (lot 1). */
final readonly class WarningsByDefault implements AssistanceLevels
{
    public function for(Dossier $dossier): AssistanceLevel
    {
        return AssistanceLevel::Warnings;
    }
}
