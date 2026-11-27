<?php

declare(strict_types=1);

namespace App\Entity\Dossier;

/**
 * A business row of a dossier (conception § 4, README of the plan § 8): it carries `dossier_id`,
 * and the Doctrine filter `dossier` (App\Doctrine\Filter\DossierFilter) adds it to every query
 * that reads one - a query outside a dossier's context throws instead of reading every dossier.
 * Use DossierOwnedTrait for the column. What a dossier holds is what DossierCopier copies; the
 * rows that say who enters a dossier (members, shares) are not its content and stay global.
 */
interface DossierOwned
{
    public function getDossier(): Dossier;
}
