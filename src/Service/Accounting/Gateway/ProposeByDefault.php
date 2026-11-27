<?php

declare(strict_types=1);

namespace App\Service\Accounting\Gateway;

use App\Entity\Dossier\Dossier;
use App\Enum\Accounting\AccountingBehaviour;
use App\Enum\Accounting\AccountingModule;

/** Without a mission (lot 2 brings them), the engine proposes every entry in the brouillard. */
final readonly class ProposeByDefault implements AccountingBehaviours
{
    public function for(Dossier $dossier, AccountingModule $module): AccountingBehaviour
    {
        return AccountingBehaviour::Propose;
    }
}
