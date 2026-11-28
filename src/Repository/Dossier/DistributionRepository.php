<?php

declare(strict_types=1);

namespace App\Repository\Dossier;

use App\Entity\Dossier\Distribution;
use App\Entity\Identity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Distribution> */
final class DistributionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Distribution::class);
    }

    /** @return list<Distribution> a teacher's distributions, the newest first */
    public function findByAuthor(User $author): array
    {
        /* @var list<Distribution> */
        return $this->createQueryBuilder('d')
            ->join('d.schoolClass', 'c')
            ->addSelect('c')
            ->andWhere('d.author = :author')
            ->setParameter('author', $author)
            ->orderBy('d.createdAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
