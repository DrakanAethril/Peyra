<?php

declare(strict_types=1);

namespace App\Repository\Mission;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\Mission\Mission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Mission> */
final class MissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Mission::class);
    }

    /** @return list<Mission> a teacher's missions, the latest first, their templates loaded */
    public function findByAuthor(User $author): array
    {
        /* @var list<Mission> */
        return $this->createQueryBuilder('m')
            ->addSelect('t')->leftJoin('m.template', 't')
            ->andWhere('m.author = :author')->setParameter('author', $author)
            ->orderBy('m.createdAt', 'DESC')->addOrderBy('m.id', 'DESC')
            ->getQuery()->getResult();
    }

    /** @return list<Mission> the missions played on a template */
    public function findByTemplate(Dossier $template): array
    {
        return $this->findBy(['template' => $template], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }
}
