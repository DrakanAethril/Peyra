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

    /** @return list<DossierShare> the templates shared with a teacher, their dossiers loaded */
    public function findForTeacher(User $teacher): array
    {
        /* @var list<DossierShare> */
        return $this->createQueryBuilder('s')
            ->join('s.dossier', 'd')
            ->addSelect('d')
            ->andWhere('s.teacher = :teacher')
            ->setParameter('teacher', $teacher)
            ->orderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * How many colleagues each of these templates is shared with.
     *
     * @param list<Dossier> $dossiers
     *
     * @return array<int, int> by dossier id
     */
    public function countByDossier(array $dossiers): array
    {
        if ([] === $dossiers) {
            return [];
        }
        /** @var list<array{dossier: int|string, shares: int|string}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('IDENTITY(s.dossier) AS dossier, COUNT(s.id) AS shares')
            ->andWhere('s.dossier IN (:dossiers)')
            ->setParameter('dossiers', $dossiers)
            ->groupBy('s.dossier')
            ->getQuery()
            ->getArrayResult();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['dossier']] = (int) $row['shares'];
        }

        return $counts;
    }

    public function findOneFor(Dossier $dossier, User $teacher): ?DossierShare
    {
        return $this->findOneBy(['dossier' => $dossier, 'teacher' => $teacher]);
    }
}
