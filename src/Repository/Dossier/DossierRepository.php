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

    /**
     * A teacher's own templates, newest first.
     *
     * @return list<Dossier>
     */
    public function findTemplatesOf(User $author): array
    {
        /* @var list<Dossier> */
        return $this->createQueryBuilder('d')
            ->andWhere('d.kind = :template')
            ->andWhere('d.owner = :author')
            ->setParameter('template', DossierKind::Template)
            ->setParameter('author', $author)
            ->orderBy('d.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * How many students' and groups' dossiers were made from each of these templates (« utilisations »).
     *
     * @param list<Dossier> $templates
     *
     * @return array<int, int> by template id
     */
    public function countCopiesOf(array $templates): array
    {
        if ([] === $templates) {
            return [];
        }
        /** @var list<array{template: int|string, copies: int|string}> $rows */
        $rows = $this->createQueryBuilder('d')
            ->select('IDENTITY(d.sourceTemplate) AS template, COUNT(d.id) AS copies')
            ->andWhere('d.sourceTemplate IN (:templates)')
            ->andWhere('d.kind IN (:kinds)')
            ->setParameter('templates', $templates)
            ->setParameter('kinds', [DossierKind::Student, DossierKind::Group])
            ->groupBy('d.sourceTemplate')
            ->getQuery()
            ->getArrayResult();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['template']] = (int) $row['copies'];
        }

        return $counts;
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
