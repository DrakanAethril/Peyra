<?php

declare(strict_types=1);

namespace App\Repository\Dossier;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierMember;
use App\Entity\Identity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<DossierMember> */
final class DossierMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DossierMember::class);
    }

    public function isMember(Dossier $dossier, User $student): bool
    {
        return null !== $this->findOneBy(['dossier' => $dossier, 'student' => $student]);
    }

    /** @return list<User> the group's students, by name */
    public function findStudents(Dossier $dossier): array
    {
        /** @var list<DossierMember> $members */
        $members = $this->createQueryBuilder('m')
            ->join('m.student', 's')
            ->addSelect('s')
            ->andWhere('m.dossier = :dossier')
            ->setParameter('dossier', $dossier)
            ->orderBy('s.sortName', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(static fn (DossierMember $member): User => $member->getStudent(), $members);
    }
}
