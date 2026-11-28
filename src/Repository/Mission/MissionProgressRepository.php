<?php

declare(strict_types=1);

namespace App\Repository\Mission;

use App\Entity\Dossier\Dossier;
use App\Entity\Mission\MissionAssignment;
use App\Entity\Mission\MissionProgress;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MissionProgress> */
final class MissionProgressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MissionProgress::class);
    }

    public function findOneFor(MissionAssignment $assignment, Dossier $dossier): ?MissionProgress
    {
        return $this->findOneBy(['assignment' => $assignment, 'dossier' => $dossier]);
    }

    /**
     * @param list<MissionAssignment> $assignments
     *
     * @return list<MissionProgress>
     */
    public function findByAssignments(array $assignments): array
    {
        if ([] === $assignments) {
            return [];
        }

        return $this->findBy(['assignment' => $assignments]);
    }
}
