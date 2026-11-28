<?php

declare(strict_types=1);

namespace App\Repository\Dossier;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierRestoration;
use App\Entity\Dossier\RestorePoint;
use App\Enum\Dossier\RestorationState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<DossierRestoration> */
final class DossierRestorationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DossierRestoration::class);
    }

    /** The return under way in a dossier, if any: one at a time. */
    public function findRunning(Dossier $dossier): ?DossierRestoration
    {
        return $this->findOneBy(['dossier' => $dossier, 'state' => RestorationState::Running]);
    }

    /** The dossier's latest return, whatever became of it. */
    public function findLatest(Dossier $dossier): ?DossierRestoration
    {
        return $this->findOneBy(['dossier' => $dossier], ['id' => 'DESC']);
    }

    /** @return list<DossierRestoration> every return still running, the oldest first */
    public function findAllRunning(): array
    {
        return $this->findBy(['state' => RestorationState::Running], ['id' => 'ASC']);
    }

    /** Whether a running return uses the point, as its target or as the point it poses first. */
    public function usesPoint(RestorePoint $point): bool
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.state = :running')
            ->andWhere('r.point = :point OR r.safetyPoint = :point')
            ->setParameter('running', RestorationState::Running)
            ->setParameter('point', $point)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}
