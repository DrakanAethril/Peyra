<?php

declare(strict_types=1);

namespace App\Repository\Identity;

use App\Entity\Identity\Enrolment;
use App\Entity\Identity\SchoolYear;
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
        /* @var list<User> */
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
        /* @var list<User> */
        return $this->createQueryBuilder('u')
            ->andWhere('u.kind = :teacher')
            ->setParameter('teacher', AccountKind::Teacher)
            ->orderBy('u.displayName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Teachers whose name or login contains the text, someone left out (a template's author picking
     * the colleagues to share it with).
     *
     * @return list<User>
     */
    public function searchTeachers(string $text, ?User $except = null, int $limit = 10): array
    {
        $like = '%'.addcslashes(mb_strtolower($text), '%_\\').'%';
        $query = $this->createQueryBuilder('u')
            ->andWhere('u.kind = :teacher')
            ->andWhere('LOWER(u.displayName) LIKE :like OR LOWER(u.login) LIKE :like')
            ->setParameter('teacher', AccountKind::Teacher)
            ->setParameter('like', $like)
            ->orderBy('u.displayName', 'ASC')
            ->setMaxResults($limit);
        if (null !== $except) {
            $query->andWhere('u != :except')->setParameter('except', $except);
        }

        /* @var list<User> */
        return $query->getQuery()->getResult();
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

        /* @var list<User> */
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

    /**
     * Students without an enrolment in the year (« À placer », conception § 3 bis), « Nom Prénom »
     * order: those still in the field, or those marked as having left it.
     *
     * @return list<User>
     */
    public function findStudentsWithoutEnrolment(SchoolYear $year, bool $departed = false): array
    {
        /* @var list<User> */
        return $this->createQueryBuilder('u')
            ->leftJoin(Enrolment::class, 'e', 'WITH', 'e.student = u AND e.schoolYear = :year')
            ->andWhere('u.kind = :student')
            ->andWhere('e.id IS NULL')
            ->andWhere($departed ? 'u.departedAt IS NOT NULL' : 'u.departedAt IS NULL')
            ->setParameter('year', $year)
            ->setParameter('student', AccountKind::Student)
            ->orderBy('u.sortName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countStudentsWithoutEnrolment(SchoolYear $year): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->leftJoin(Enrolment::class, 'e', 'WITH', 'e.student = u AND e.schoolYear = :year')
            ->andWhere('u.kind = :student')
            ->andWhere('e.id IS NULL')
            ->andWhere('u.departedAt IS NULL')
            ->setParameter('year', $year)
            ->setParameter('student', AccountKind::Student)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<User> every student account, « Nom Prénom » order (when no year exists yet) */
    public function findStudents(): array
    {
        /* @var list<User> */
        return $this->createQueryBuilder('u')
            ->andWhere('u.kind = :student')
            ->setParameter('student', AccountKind::Student)
            ->orderBy('u.sortName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Students whose name or login contains the text, for « Ajouter des étudiants » to a class.
     *
     * @return list<User>
     */
    public function searchStudents(string $text, int $limit = 10): array
    {
        $like = '%'.addcslashes(mb_strtolower($text), '%_\\').'%';

        /* @var list<User> */
        return $this->createQueryBuilder('u')
            ->andWhere('u.kind = :student')
            ->andWhere('LOWER(u.displayName) LIKE :like OR LOWER(u.sortName) LIKE :like OR LOWER(u.login) LIKE :like')
            ->setParameter('student', AccountKind::Student)
            ->setParameter('like', $like)
            ->orderBy('u.sortName', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Students among these ids, « Nom Prénom » order (ids that are not students are dropped).
     *
     * @param list<int> $ids
     *
     * @return list<User>
     */
    public function findStudentsByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /* @var list<User> */
        return $this->createQueryBuilder('u')
            ->andWhere('u.kind = :student')
            ->andWhere('u.id IN (:ids)')
            ->setParameter('student', AccountKind::Student)
            ->setParameter('ids', $ids)
            ->orderBy('u.sortName', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
