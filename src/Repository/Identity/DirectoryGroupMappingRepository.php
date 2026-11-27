<?php

declare(strict_types=1);

namespace App\Repository\Identity;

use App\Entity\Identity\DirectoryGroupMapping;
use App\Entity\Identity\SchoolYear;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<DirectoryGroupMapping> */
final class DirectoryGroupMappingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DirectoryGroupMapping::class);
    }

    /** @return array<string, DirectoryGroupMapping> the year's mappings, by group */
    public function findByYear(SchoolYear $year): array
    {
        $byGroup = [];
        foreach ($this->findBy(['schoolYear' => $year], ['directoryGroup' => 'ASC']) as $mapping) {
            $byGroup[$mapping->getDirectoryGroup()] = $mapping;
        }

        return $byGroup;
    }
}
