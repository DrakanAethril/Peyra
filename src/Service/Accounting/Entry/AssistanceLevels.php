<?php

declare(strict_types=1);

namespace App\Service\Accounting\Entry;

use App\Entity\Dossier\Dossier;
use App\Enum\Accounting\AssistanceLevel;

/** The assistance level a dossier is worked at now (App\Service\Shared\Assistance\StudentAssistanceLevels); what it means, Assistance::at(). */
interface AssistanceLevels
{
    public function for(Dossier $dossier): AssistanceLevel;
}
