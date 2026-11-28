<?php

declare(strict_types=1);

namespace App\Repository\Dossier;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierShare;
use App\Entity\Identity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<DossierShare> */
final class DossierShareRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DossierShare::class);
    }

    /** @return list<DossierShare> the colleagues a template is shared with, by name */
    public function findByDossier(Dossier $dossier): array
    {
        /* @var list<DossierShare> */
        return $this->createQueryBuilder('s')
            ->join('s.teacher', 't')
            ->addSelect('t')
            ->andWhere('s.dossier = :dossier')
            ->setParameter('dossier', $dossier)
            ->orderBy('t.sortName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneFor(Dossier $dossier, User $teacher): ?DossierShare
    {
        return $this->findOneBy(['dossier' => $dossier, 'teacher' => $teacher]);
    }
}
