<?php

declare(strict_types=1);

namespace App\Repository\Dossier;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierMember;
use App\Entity\Identity\User;
use App\Enum\Dossier\DossierKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Dossier> */
final class DossierRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Dossier::class);
    }

    /**
     * A student's dossiers, every year: their own and those of the groups they are in, the most
     * recent school year first, then by name.
     *
     * @return list<Dossier>
     */
    public function findForStudent(User $student): array
    {
        /* @var list<Dossier> */
        return $this->createQueryBuilder('d')
            ->leftJoin('d.schoolYear', 'y')
            ->addSelect('y')
            ->andWhere('(d.kind = :student AND d.owner = :me) OR (d.kind = :group AND d.id IN (SELECT IDENTITY(m.dossier) FROM '.DossierMember::class.' m WHERE m.student = :me))')
            ->setParameter('student', DossierKind::Student)
            ->setParameter('group', DossierKind::Group)
            ->setParameter('me', $student)
            ->orderBy('y.startsOn', 'DESC')
            ->addOrderBy('d.company.denomination', 'ASC')
            ->addOrderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The common library's templates (no author), oldest first.
     *
     * @return list<Dossier>
     */
    public function findCommonTemplates(): array
    {
        /* @var list<Dossier> */
        return $this->createQueryBuilder('d')
            ->andWhere('d.kind = :template')
            ->andWhere('d.owner IS NULL')
            ->setParameter('template', DossierKind::Template)
            ->orderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findCommonTemplateNamed(string $denomination): ?Dossier
    {
        foreach ($this->findCommonTemplates() as $template) {
            if ($template->getCompany()->getDenomination() === $denomination) {
                return $template;
            }
        }

        return null;
    }
}
