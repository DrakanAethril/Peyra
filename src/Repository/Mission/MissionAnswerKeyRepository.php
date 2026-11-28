<?php

declare(strict_types=1);

namespace App\Repository\Mission;

use App\Entity\Dossier\Dossier;
use App\Entity\Mission\Mission;
use App\Entity\Mission\MissionAnswerKey;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MissionAnswerKey> */
final class MissionAnswerKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MissionAnswerKey::class);
    }

    public function findOneByMission(Mission $mission): ?MissionAnswerKey
    {
        return $this->findOneBy(['mission' => $mission]);
    }

    public function findOneByDossier(Dossier $dossier): ?MissionAnswerKey
    {
        return $this->findOneBy(['dossier' => $dossier]);
    }
}
