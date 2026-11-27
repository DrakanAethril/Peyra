<?php

declare(strict_types=1);

namespace App\Repository\Identity;

use App\Entity\Identity\User;
use App\Enum\Identity\AccountKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<User> */
final class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByDirectoryId(string $directoryId): ?User
    {
        return $this->findOneBy(['directoryId' => $directoryId]);
    }

    /** @return list<User> administrators, oldest first */
    public function findAdministrators(): array
    {
        /** @var list<User> */
        return $this->createQueryBuilder('u')
            ->andWhere('u.administrator = true')
            ->andWhere('u.kind = :teacher')
            ->setParameter('teacher', AccountKind::Teacher)
            ->orderBy('u.administratorSince', 'ASC')
            ->addOrderBy('u.displayName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countAdministrators(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.administrator = true')
            ->andWhere('u.kind = :teacher')
            ->setParameter('teacher', AccountKind::Teacher)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<User> every teacher account, by name */
    public function findTeachers(): array
    {
        /** @var list<User> */
        return $this->createQueryBuilder('u')
            ->andWhere('u.kind = :teacher')
            ->setParameter('teacher', AccountKind::Teacher)
            ->orderBy('u.displayName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Teachers whose name or login contains the text, for the autocomplete of « Désigner un
     * administrateur » (administrators excluded: they are already on the list).
     *
     * @return list<User>
     */
    public function searchTeachersNotAdministrators(string $text, int $limit = 10): array
    {
        $like = '%'.addcslashes(mb_strtolower($text), '%_\\').'%';

        /** @var list<User> */
        return $this->createQueryBuilder('u')
            ->andWhere('u.kind = :teacher')
            ->andWhere('u.administrator = false')
            ->andWhere('LOWER(u.displayName) LIKE :like OR LOWER(u.login) LIKE :like')
            ->setParameter('teacher', AccountKind::Teacher)
            ->setParameter('like', $like)
            ->orderBy('u.displayName', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
