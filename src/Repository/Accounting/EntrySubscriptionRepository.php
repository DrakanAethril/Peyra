<?php

declare(strict_types=1);

namespace App\Repository\Accounting;

use App\Entity\Accounting\EntrySubscription;
use App\Entity\Accounting\EntryTemplate;
use App\Entity\Dossier\Dossier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Subscriptions are DossierOwned: every method reads inside the current dossier's context.
 *
 * @extends ServiceEntityRepository<EntrySubscription>
 */
final class EntrySubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EntrySubscription::class);
    }

    /** @return list<EntrySubscription> by label, their template and its journal loaded */
    public function findByDossier(Dossier $dossier): array
    {
        /* @var list<EntrySubscription> */
        return $this->createQueryBuilder('s')
            ->innerJoin('s.template', 't')
            ->addSelect('t')
            ->innerJoin('t.journal', 'j')
            ->addSelect('j')
            ->andWhere('s.dossier = :dossier')
            ->setParameter('dossier', $dossier)
            ->orderBy('s.label', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneInDossier(Dossier $dossier, int $id): ?EntrySubscription
    {
        /* @var EntrySubscription|null */
        return $this->createQueryBuilder('s')
            ->andWhere('s.dossier = :dossier')
            ->andWhere('s.id = :id')
            ->setParameter('dossier', $dossier)
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<string> the labels of the subscriptions that call the template */
    public function labelsCalling(EntryTemplate $template): array
    {
        /** @var list<array{label: string}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('s.label')
            ->andWhere('s.template = :template')
            ->setParameter('template', $template)
            ->orderBy('s.label', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): string => $row['label'], $rows);
    }
}
