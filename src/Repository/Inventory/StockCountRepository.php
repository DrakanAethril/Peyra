<?php

declare(strict_types=1);

namespace App\Repository\Inventory;

use App\Entity\Dossier\Dossier;
use App\Entity\Inventory\StockCount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The physical inventories are DossierOwned: every method reads inside the dossier's context.
 *
 * @extends ServiceEntityRepository<StockCount>
 */
final class StockCountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StockCount::class);
    }

    /** @return list<StockCount> the latest first */
    public function findLatestFirst(Dossier $dossier): array
    {
        return $this->findBy(['dossier' => $dossier], ['date' => 'DESC', 'id' => 'DESC']);
    }
}
