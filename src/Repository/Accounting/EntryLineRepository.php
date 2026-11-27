<?php

declare(strict_types=1);

namespace App\Repository\Accounting;

use App\Entity\Accounting\Account;
use App\Entity\Accounting\EntryLine;
use App\Entity\Dossier\Dossier;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Accounting\EntryState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The lines of the dossier's entries, read inside its context (the `dossier` filter). The
 * readings of balances are App\Service\Accounting\Ledger\LedgerReader's; this repository answers
 * the guards of the plan's fiches: does an account, a third party carry lines?
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
}
