<?php

declare(strict_types=1);

namespace App\Entity\Dossier;

use Doctrine\ORM\Mapping as ORM;

/** The `dossier_id` column of a DossierOwned row; the filter reads that column by this name. */
trait DossierOwnedTrait
{
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'dossier_id', nullable: false, onDelete: 'CASCADE')]
    private Dossier $dossier;

    public function getDossier(): Dossier
    {
        return $this->dossier;
    }
}
