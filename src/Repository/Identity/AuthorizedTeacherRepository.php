<?php

declare(strict_types=1);

namespace App\Repository\Identity;

use App\Entity\Identity\AuthorizedTeacher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AuthorizedTeacher> */
final class AuthorizedTeacherRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuthorizedTeacher::class);
    }

    public function findActiveByDirectoryId(string $directoryId): ?AuthorizedTeacher
    {
        return $this->findOneBy(['directoryId' => $directoryId, 'revokedAt' => null]);
    }

    public function isAuthorized(string $directoryId): bool
    {
        return null !== $this->findActiveByDirectoryId($directoryId);
    }

    /** @return list<AuthorizedTeacher> the teachers authorised today, by name */
    public function findActive(): array
    {
        /** @var list<AuthorizedTeacher> */
        return $this->createQueryBuilder('a')
            ->andWhere('a.revokedAt IS NULL')
            ->orderBy('a.displayName', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
