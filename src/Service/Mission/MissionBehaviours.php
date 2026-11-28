<?php

declare(strict_types=1);

namespace App\Service\Mission;

use App\Entity\Dossier\Dossier;
use App\Enum\Accounting\AccountingBehaviour;
use App\Enum\Accounting\AccountingModule;
use App\Service\Accounting\Gateway\AccountingBehaviours;
use App\Service\Accounting\Gateway\ProposeByDefault;

/**
 * The behaviour of the modules (design-ux.md § 3.4) is the mission's the dossier plays (step 2.9a),
 * the same for every module; without a mission, ProposeByDefault's.
 */
final readonly class MissionBehaviours implements AccountingBehaviours
{
    public function __construct(private CurrentMission $missions)
    {
    }

    public function for(Dossier $dossier, AccountingModule $module): AccountingBehaviour
    {
        return $this->missions->of($dossier)?->getBehaviour() ?? new ProposeByDefault()->for($dossier, $module);
    }
}
