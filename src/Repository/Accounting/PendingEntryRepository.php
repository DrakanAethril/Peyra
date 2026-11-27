<?php

declare(strict_types=1);

namespace App\Repository\Accounting;

use App\Entity\Accounting\Journal;
use App\Entity\Accounting\PendingEntry;
use App\Entity\Dossier\Period;
use App\Entity\Identity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The entries typed and not yet saved to the brouillard, read inside the dossier's context.
 *
 * @extends ServiceEntityRepository<PendingEntry>
 */
final class PendingEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PendingEntry::class);
    }

    public function findOne(Journal $journal, Period $period, User $author): ?PendingEntry
    {
        return $this->findOneBy(['journal' => $journal, 'period' => $period, 'author' => $author]);
    }

    /**
     * Everybody's pending work in the context's dossiers, newest first; narrowed to a journal and a
     * month when given.
     *
     * @return list<PendingEntry>
     */
    public function findAllPending(?Journal $journal = null, ?Period $period = null): array
    {
        $query = $this->createQueryBuilder('p')->orderBy('p.savedAt', 'DESC');
        if (null !== $journal) {
            $query->andWhere('p.journal = :journal')->setParameter('journal', $journal);
        }
        if (null !== $period) {
            $query->andWhere('p.period = :period')->setParameter('period', $period);
        }

        /* @var list<PendingEntry> */
        return $query->getQuery()->getResult();
    }
}
