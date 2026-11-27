<?php

declare(strict_types=1);

namespace App\Repository\Accounting;

use App\Entity\Accounting\Entry;
use App\Entity\Accounting\Journal;
use App\Entity\Dossier\Exercise;
use App\Entity\Dossier\Period;
use App\Enum\Accounting\EntryState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The dossier's entries, read inside its context (the `dossier` filter). Written by the Ledger
 * alone.
 *
 * @extends ServiceEntityRepository<Entry>
 */
final class EntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Entry::class);
    }

    /**
     * The brouillard, narrowed to a journal and/or a period, in date order then as drafted.
     *
     * @return list<Entry>
     */
    public function findDrafts(?Journal $journal = null, ?Period $period = null): array
    {
        $query = $this->createQueryBuilder('e')
            ->andWhere('e.state = :draft')
            ->setParameter('draft', EntryState::Draft)
            ->orderBy('e.entryDate', 'ASC')
            ->addOrderBy('e.id', 'ASC');
        if (null !== $journal) {
            $query->andWhere('e.journal = :journal')->setParameter('journal', $journal);
        }
        if (null !== $period) {
            $query->andWhere('e.period = :period')->setParameter('period', $period);
        }

        /* @var list<Entry> */
        return $query->getQuery()->getResult();
    }

    /** The highest number of the journal's sequence in the exercise, 0 before the first. */
    public function lastNumber(Journal $journal, Exercise $exercise): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('MAX(e.number)')
            ->andWhere('e.journal = :journal')
            ->andWhere('e.exercise = :exercise')
            ->setParameter('journal', $journal)
            ->setParameter('exercise', $exercise)
            ->getQuery()->getSingleScalarResult();
    }

    /** The reversal of a validated entry, or null while it stands. */
    public function findReversalOf(Entry $entry): ?Entry
    {
        return $this->findOneBy(['reversalOf' => $entry]);
    }

    /** How many entries the journal carries; `validated` counts the validated ones only. */
    public function countInJournal(Journal $journal, bool $validated = false): int
    {
        $query = $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.journal = :journal')
            ->setParameter('journal', $journal);
        if ($validated) {
            $query->andWhere('e.state = :validated')->setParameter('validated', EntryState::Validated);
        }

        return (int) $query->getQuery()->getSingleScalarResult();
    }

    /**
     * The entries of a journal in a month, as the entry grid lists them: by date, then in the order they
     * were written. Lines are loaded with them.
     *
     * @return list<Entry>
     */
    public function findInJournalPeriod(Journal $journal, Period $period): array
    {
        /* @var list<Entry> */
        return $this->createQueryBuilder('e')
            ->addSelect('l', 'a', 't')
            ->leftJoin('e.lines', 'l')
            ->leftJoin('l.account', 'a')
            ->leftJoin('l.thirdParty', 't')
            ->andWhere('e.journal = :journal')
            ->andWhere('e.period = :period')
            ->setParameter('journal', $journal)
            ->setParameter('period', $period)
            ->orderBy('e.entryDate', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->addOrderBy('l.position', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * The entries of the journal in the exercise that carry this document reference, but the one
     * given: what makes a document a possible duplicate (EntryControls).
     *
     * @return list<Entry>
     */
    public function findByDocument(Journal $journal, Exercise $exercise, string $document, ?int $except = null): array
    {
        $query = $this->createQueryBuilder('e')
            ->andWhere('e.journal = :journal')
            ->andWhere('e.exercise = :exercise')
            ->andWhere('e.document = :document')
            ->setParameter('journal', $journal)
            ->setParameter('exercise', $exercise)
            ->setParameter('document', $document)
            ->orderBy('e.id', 'ASC');
        if (null !== $except) {
            $query->andWhere('e.id <> :except')->setParameter('except', $except);
        }

        /* @var list<Entry> */
        return $query->getQuery()->getResult();
    }
}
