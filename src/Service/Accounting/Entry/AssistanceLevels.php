<?php

declare(strict_types=1);

namespace App\Service\Accounting\Entry;

use App\Entity\Dossier\Dossier;
use App\Enum\Accounting\AssistanceLevel;

/** The assistance level a dossier is worked at now: its mission's (step 2.9), else the default. */
interface AssistanceLevels
{
    public function for(Dossier $dossier): AssistanceLevel;
}
