<?php

declare(strict_types=1);

namespace App\Repository\Accounting;

use App\Entity\Accounting\Account;
use App\Entity\Accounting\EntryLine;
use App\Entity\Dossier\Dossier;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Accounting\EntryState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The lines of the dossier's entries, read inside its context (the `dossier` filter). The
 * readings of balances are App\Service\Accounting\Ledger\LedgerReader's; this repository answers
 * the guards of the plan's fiches (does an account, a third party carry lines?) and the lettering's
 * readings (the lines of an account or of a third party on its collective).
 *
 * @extends ServiceEntityRepository<EntryLine>
 */
final class EntryLineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EntryLine::class);
    }

    /** How many lines name the account; `validated` counts those of validated entries only. */
    public function countOnAccount(Account $account, bool $validated = false): int
    {
        $query = $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->andWhere('l.account = :account')
            ->setParameter('account', $account);
        if ($validated) {
            $query->join('l.entry', 'e')->andWhere('e.state = :validated')->setParameter('validated', EntryState::Validated);
        }

        return (int) $query->getQuery()->getSingleScalarResult();
    }

    /** How many lines name the third party. */
    public function countOnThirdParty(ThirdParty $thirdParty): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->andWhere('l.thirdParty = :thirdParty')
            ->setParameter('thirdParty', $thirdParty)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * How many lines each account carries, by account id (the plan's import).
     *
     * @return array<int, int>
     */
    public function countByAccount(Dossier $dossier): array
    {
        return $this->countBy('l.account', $dossier);
    }

    /**
     * How many lines name each third party, by third party id (the third parties' import).
     *
     * @return array<int, int>
     */
    public function countByThirdParty(Dossier $dossier): array
    {
        return $this->countBy('l.thirdParty', $dossier);
    }

    /** @return array<int, int> */
    private function countBy(string $field, Dossier $dossier): array
    {
        /** @var list<array{id: int|string, n: int|string}> $rows */
        $rows = $this->createQueryBuilder('l')
            ->select(\sprintf('IDENTITY(%s) AS id, COUNT(l.id) AS n', $field))
            ->andWhere('l.dossier = :dossier')
            ->andWhere($field.' IS NOT NULL')
            ->setParameter('dossier', $dossier)
            ->groupBy($field)
            ->getQuery()
            ->getArrayResult();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['id']] = (int) $row['n'];
        }

        return $counts;
    }

    /** How many lines of the account carry a lettering letter. */
    public function countLetteredOnAccount(Account $account): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->andWhere('l.account = :account')
            ->andWhere('l.letter IS NOT NULL')
            ->setParameter('account', $account)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * The lines of an account - narrowed to a third party's when one is given -, in date order,
     * then entry and position. `validated` keeps the validated entries' only;
     * `refresh` reads the rows again over what the request already holds (under the lettering's
     * lock).
     *
     * @return list<EntryLine>
     */
    public function findOnAccount(Account $account, ?ThirdParty $thirdParty, bool $validated, bool $refresh = false): array
    {
        $query = $this->createQueryBuilder('l')
            ->join('l.entry', 'e')
            ->andWhere('l.account = :account')
            ->setParameter('account', $account)
            ->orderBy('e.entryDate')
            ->addOrderBy('e.id')
            ->addOrderBy('l.position');
        if (null !== $thirdParty) {
            $query->andWhere('l.thirdParty = :thirdParty')->setParameter('thirdParty', $thirdParty);
        }
        if ($validated) {
            $query->andWhere('e.state = :validated')->setParameter('validated', EntryState::Validated);
        }
        // The entries come with their lines, unless the lines are read again: refreshing an entry
        // would drop the lines it already holds.
        if (!$refresh) {
            $query->addSelect('e');
        }
        $result = $query->getQuery();
        if ($refresh) {
            $result->setHint(Query::HINT_REFRESH, true);
        }

        /* @var list<EntryLine> */
        return $result->getResult();
    }

    /**
     * Lines of the dossier by id, with their entries; an id that names none is left out.
     *
     * @param list<int> $ids
     *
     * @return list<EntryLine>
     */
    public function findByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /* @var list<EntryLine> */
        return $this->createQueryBuilder('l')
            ->addSelect('e')
            ->join('l.entry', 'e')
            ->andWhere('l.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('l.id')
            ->getQuery()->getResult();
    }

    /**
     * The validated lines of every lettrable account, with their account and third party: what the
     * lettering's list adds up per account and third party.
     *
     * @return list<EntryLine>
     */
    public function findValidatedOnLettrableAccounts(): array
    {
        /* @var list<EntryLine> */
        return $this->createQueryBuilder('l')
            ->addSelect('a', 't')
            ->join('l.entry', 'e')
            ->join('l.account', 'a')
            ->leftJoin('l.thirdParty', 't')
            ->andWhere('e.state = :validated')
            ->andWhere('a.lettrable = true')
            ->setParameter('validated', EntryState::Validated)
            ->orderBy('a.number')
            ->addOrderBy('t.code')
            ->getQuery()->getResult();
    }

    /**
     * How many lines of the brouillard sit on an account (narrowed to a third party's): they are
     * lettered once validated.
     */
    public function countDraftsOnAccount(Account $account, ?ThirdParty $thirdParty): int
    {
        $query = $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->join('l.entry', 'e')
            ->andWhere('l.account = :account')
            ->andWhere('e.state = :draft')
            ->setParameter('account', $account)
            ->setParameter('draft', EntryState::Draft);
        if (null !== $thirdParty) {
            $query->andWhere('l.thirdParty = :thirdParty')->setParameter('thirdParty', $thirdParty);
        }

        return (int) $query->getQuery()->getSingleScalarResult();
    }

    /**
     * The lettrable collective accounts the third party's validated lines sit on, by number.
     *
     * @return list<Account>
     */
    public function lettrableAccountsOf(ThirdParty $thirdParty): array
    {
        /* @var list<Account> */
        return $this->getEntityManager()->createQuery(
            'SELECT a FROM '.Account::class.' a WHERE a.lettrable = true AND a.collective = true AND EXISTS ('
            .'SELECT l.id FROM '.EntryLine::class.' l JOIN l.entry e WHERE l.account = a AND l.thirdParty = :thirdParty AND e.state = :validated'
            .') ORDER BY a.number',
        )->setParameter('thirdParty', $thirdParty)->setParameter('validated', EntryState::Validated)->getResult();
    }

    /**
     * The account of a class (6: an expense, 7: a revenue) that the latest entry naming this third
     * party carried - what a pièce of theirs proposes (step 2.2a, the way Pennylane learns a
     * supplier's category); null when none of their entries has one.
     */
    public function usualAccountOf(ThirdParty $thirdParty, int $classDigit): ?string
    {
        $number = $this->getEntityManager()->createQueryBuilder()
            ->select('a.number')
            ->from(EntryLine::class, 'l')
            ->join('l.entry', 'e')
            ->join('e.lines', 'o')
            ->join('o.account', 'a')
            ->andWhere('l.thirdParty = :thirdParty')
            ->andWhere('a.number LIKE :class')
            ->setParameter('thirdParty', $thirdParty)
            ->setParameter('class', $classDigit.'%')
            ->orderBy('e.entryDate', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->addOrderBy('o.position')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult(Query::HYDRATE_SINGLE_SCALAR);

        return \is_string($number) ? $number : null;
    }
}
