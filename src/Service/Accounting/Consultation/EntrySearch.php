<?php

declare(strict_types=1);

namespace App\Service\Accounting\Consultation;

use App\Entity\Accounting\Entry;
use App\Entity\Accounting\EntryLine;
use App\Enum\Accounting\EntryState;
use App\Service\Shared\ListPage;
use App\Service\Shared\Money;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * « Recherche d'écritures » (lot 1, step 1.5; design-ux.md § 3.2): the dossier's entries that meet
 * every filter of EntrySearchCriteria - in the window, of the journal, of the state, whose document
 * or a label (the entry's or a line's) holds the text typed, with a line on the account or naming
 * the third party, with a line of that exact amount. Newest first unless asked otherwise (§ 6.6).
 * The amount of an entry is its total debit; the filter's amount adds them all. Runs in the
 * dossier's context.
 */
final readonly class EntrySearch
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function page(EntrySearchCriteria $criteria, ListPage $page): EntrySearchResult
    {
        $total = (int) $this->matching($criteria)->select('COUNT(e.id)')->getQuery()->getSingleScalarResult();
        $page = $page->within($total);

        $ids = [];
        foreach ($this->ordered($criteria)->select('e.id')->setFirstResult($page->offset())->setMaxResults($page->perPage)->getQuery()->getScalarResult() as $row) {
            if (\is_array($row) && \is_numeric($row['id'] ?? null)) {
                $ids[] = (int) $row['id'];
            }
        }

        return new EntrySearchResult($this->load($ids, $criteria), $total, $this->amount($criteria));
    }

    /**
     * How many entries of the filter, the state apart, each state counts: the chips' counters.
     *
     * @return array{all: int, draft: int, validated: int}
     */
    public function countByState(EntrySearchCriteria $criteria): array
    {
        $any = clone $criteria;
        $any->state = null;
        $counts = ['all' => 0, 'draft' => 0, 'validated' => 0];
        foreach ($this->matching($any)->select('e.state AS state, COUNT(e.id) AS n')->groupBy('e.state')->getQuery()->getScalarResult() as $row) {
            if (!\is_array($row) || !\is_numeric($row['n'] ?? null)) {
                continue;
            }
            $state = $row['state'] ?? null;
            $key = $state instanceof EntryState ? $state->value : (\is_string($state) ? $state : '');
            if (isset($counts[$key])) {
                $counts[$key] = (int) $row['n'];
                $counts['all'] += (int) $row['n'];
            }
        }

        return $counts;
    }

    /**
     * Every entry of the filter, for an export.
     *
     * @return list<Entry>
     */
    public function all(EntrySearchCriteria $criteria): array
    {
        $ids = [];
        foreach ($this->ordered($criteria)->select('e.id')->getQuery()->getScalarResult() as $row) {
            if (\is_array($row) && \is_numeric($row['id'] ?? null)) {
                $ids[] = (int) $row['id'];
            }
        }

        return $this->load($ids, $criteria);
    }

    /**
     * @param list<int> $ids
     *
     * @return list<Entry>
     */
    private function load(array $ids, EntrySearchCriteria $criteria): array
    {
        if ([] === $ids) {
            return [];
        }
        $query = $this->entityManager->createQueryBuilder()
            ->select('e', 'j', 'l', 'a', 't')
            ->from(Entry::class, 'e')
            ->join('e.journal', 'j')
            ->leftJoin('e.lines', 'l')
            ->leftJoin('l.account', 'a')
            ->leftJoin('l.thirdParty', 't')
            ->andWhere('e.id IN (:ids)')
            ->setParameter('ids', $ids);
        $this->order($query, $criteria);
        $query->addOrderBy('l.position', 'ASC');

        /** @var list<Entry> $entries */
        $entries = $query->getQuery()->getResult();

        return $entries;
    }

    private function amount(EntrySearchCriteria $criteria): Money
    {
        $sum = $this->entityManager->createQueryBuilder()
            ->select('SUM(sl.debit)')
            ->from(EntryLine::class, 'sl')
            ->join('sl.entry', 'e');
        $this->narrow($sum, $criteria);
        $value = $sum->getQuery()->getSingleScalarResult();

        return Money::cents(\is_numeric($value) ? (int) $value : 0);
    }

    private function ordered(EntrySearchCriteria $criteria): QueryBuilder
    {
        $query = $this->matching($criteria);
        $this->order($query, $criteria);

        return $query;
    }

    private function order(QueryBuilder $query, EntrySearchCriteria $criteria): void
    {
        $direction = $criteria->newestFirst ? 'DESC' : 'ASC';
        $query->orderBy('e.entryDate', $direction)->addOrderBy('e.id', $direction);
    }

    private function matching(EntrySearchCriteria $criteria): QueryBuilder
    {
        $query = $this->entityManager->createQueryBuilder()->from(Entry::class, 'e');
        $this->narrow($query, $criteria);

        return $query;
    }

    private function narrow(QueryBuilder $query, EntrySearchCriteria $criteria): void
    {
        $query->andWhere('e.entryDate >= :from')
            ->andWhere('e.entryDate <= :to')
            ->setParameter('from', $criteria->window->from->format('Y-m-d'))
            ->setParameter('to', $criteria->window->to->format('Y-m-d'));
        if (null !== $criteria->journal) {
            $query->andWhere('e.journal = :journal')->setParameter('journal', $criteria->journal);
        }
        if (null !== $criteria->state) {
            $query->andWhere('e.state = :state')->setParameter('state', $criteria->state);
        }
        if ('' !== $criteria->text) {
            $query->andWhere(\sprintf('(LOWER(e.document) LIKE :text OR LOWER(e.label) LIKE :text OR EXISTS (SELECT ql.id FROM %s ql WHERE ql.entry = e AND LOWER(ql.label) LIKE :text))', EntryLine::class))
                ->setParameter('text', self::like($criteria->text));
        }
        $subject = $criteria->subject;
        if (null !== $subject?->account) {
            $query->andWhere(\sprintf('EXISTS (SELECT al.id FROM %s al WHERE al.entry = e AND al.account = :account)', EntryLine::class))
                ->setParameter('account', $subject->account);
        } elseif (null !== $subject?->thirdParty) {
            $query->andWhere(\sprintf('EXISTS (SELECT tl.id FROM %s tl WHERE tl.entry = e AND tl.thirdParty = :party)', EntryLine::class))
                ->setParameter('party', $subject->thirdParty);
        }
        if (null !== $criteria->amount) {
            $query->andWhere(\sprintf('EXISTS (SELECT ml.id FROM %s ml WHERE ml.entry = e AND (ml.debit = :amount OR ml.credit = :amount))', EntryLine::class))
                ->setParameter('amount', $criteria->amount->cents);
        }
    }

    /** « %fac-12% », the typed text lower-cased and its own wildcards escaped. */
    private static function like(string $text): string
    {
        return '%'.addcslashes(mb_strtolower($text), '%_\\').'%';
    }
}
