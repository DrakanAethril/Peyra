<?php

declare(strict_types=1);

namespace App\Service\Accounting\Gateway;

use App\Entity\Dossier\Dossier;
use App\Enum\Accounting\AccountingBehaviour;
use App\Enum\Accounting\AccountingModule;

/**
 * Which behaviour applies to a module's proposal in a dossier (design-ux.md § 3.4). The mission
 * played in the dossier sets it at step 2.9; until then ProposeByDefault answers.
 */
interface AccountingBehaviours
{
    public function for(Dossier $dossier, AccountingModule $module): AccountingBehaviour;
}
