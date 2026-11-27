<?php

declare(strict_types=1);

namespace App\Service\Accounting\Ledger;

use App\Entity\Accounting\Account;
use App\Entity\Accounting\EntryLine;
use App\Entity\Dossier\Dossier;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Accounting\EntryState;
use App\Repository\Accounting\AccountRepository;
use App\Repository\Dossier\ExerciseRepository;
use App\Service\Shared\Money;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Balances are a reading, never a stored counter (conception § 5, README of the plan § 8): every
 * figure here is an aggregate query over the entry lines, inside the dossier's context.
 *
 * A reading covers a window of days, `from` to `to` inclusive, and a scope (EntryScope: validated
 * entries only, or the brouillard with them). `from` null reads from the first line; the screens
 * pass the start of the exercise (exerciseStart()), since an exercise opens on its own à-nouveaux.
 */
final readonly class LedgerReader
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ExerciseRepository $exercises,
        private AccountRepository $accounts,
    ) {
    }

    /** The first day of the exercise the day falls in, or null when no exercise holds it. */
    public function exerciseStart(Dossier $dossier, \DateTimeImmutable $day): ?\DateTimeImmutable
    {
        return $this->exercises->findAt($dossier, $day)?->getStartsOn();
    }

    /**
     * What each account's lines add up to, for the accounts that carry lines in the window.
     *
     * @return array<int, Totals> by account id
     */
    public function accountTotals(Dossier $dossier, ?\DateTimeImmutable $from, \DateTimeImmutable $to, EntryScope $scope): array
    {
        return $this->totalsBy('l.account', $dossier, $from, $to, $scope);
    }

    public function accountBalance(Account $account, ?\DateTimeImmutable $from, \DateTimeImmutable $to, EntryScope $scope): Totals
    {
        return $this->totalsBy('l.account', $account->getDossier(), $from, $to, $scope, ['l.account = :one', $account])[(int) $account->getId()] ?? Totals::zero();
    }

    /**
     * What each third party's lines add up to (its outstanding amount: what it owes, or is owed),
     * for the third parties named on lines in the window.
     *
     * @return array<int, Totals> by third party id
     */
    public function thirdPartyTotals(Dossier $dossier, ?\DateTimeImmutable $from, \DateTimeImmutable $to, EntryScope $scope): array
    {
        return $this->totalsBy('l.thirdParty', $dossier, $from, $to, $scope, ['l.thirdParty IS NOT NULL', null]);
    }

    public function thirdPartyBalance(ThirdParty $thirdParty, ?\DateTimeImmutable $from, \DateTimeImmutable $to, EntryScope $scope): Totals
    {
        return $this->totalsBy('l.thirdParty', $thirdParty->getDossier(), $from, $to, $scope, ['l.thirdParty = :one', $thirdParty])[(int) $thirdParty->getId()] ?? Totals::zero();
    }

    /**
     * An account's lines in the window, in date order then as entered, each with the balance after
     * it - starting from what the account carried before `from` when `before` is given.
     *
     * @return list<LedgerMovement>
     */
    public function movements(Account $account, ?\DateTimeImmutable $from, \DateTimeImmutable $to, EntryScope $scope, ?Totals $before = null): array
    {
        $query = $this->lines($account->getDossier(), $from, $to, $scope)
            ->andWhere('l.account = :account')
            ->setParameter('account', $account);

        return self::withBalances($query->getQuery()->getResult(), ($before ?? Totals::zero())->balance());
    }

    /**
     * A third party's lines in the window - on its collective, or on another account of its nature
     * (416000, 419100) -, in date order then as entered, each with the balance after it, starting
     * from what it carried before `from` when `before` is given.
     *
     * @return list<LedgerMovement>
     */
    public function thirdPartyMovements(ThirdParty $thirdParty, ?\DateTimeImmutable $from, \DateTimeImmutable $to, EntryScope $scope, ?Totals $before = null): array
    {
        $query = $this->lines($thirdParty->getDossier(), $from, $to, $scope)
            ->andWhere('l.thirdParty = :thirdParty')
            ->setParameter('thirdParty', $thirdParty);

        return self::withBalances($query->getQuery()->getResult(), ($before ?? Totals::zero())->balance());
    }

    /**
     * The trial balance (« balance générale ») of the window: every account that carries lines in
     * it, in number order, with its totals.
     *
     * @return list<TrialBalanceRow>
     */
    public function trialBalance(Dossier $dossier, ?\DateTimeImmutable $from, \DateTimeImmutable $to, EntryScope $scope): array
    {
        $totals = $this->accountTotals($dossier, $from, $to, $scope);
        $rows = [];
        foreach ($this->accounts->findPlan($dossier) as $account) {
            $found = $totals[(int) $account->getId()] ?? null;
            if (null !== $found) {
                $rows[] = new TrialBalanceRow($account, $found);
            }
        }

        return $rows;
    }

    /**
     * The general ledger (« grand livre ») of the window: every account with lines in the window or
     * before it in the exercise of `from`, in number order - what it carried before, its movements,
     * its total at the end.
     *
     * @return list<GeneralLedgerAccount>
     */
    public function generalLedger(Dossier $dossier, \DateTimeImmutable $from, \DateTimeImmutable $to, EntryScope $scope): array
    {
        $start = $this->exerciseStart($dossier, $from) ?? $from;
        $before = $start < $from ? $this->accountTotals($dossier, $start, $from->modify('-1 day'), $scope) : [];
        $within = $this->accountTotals($dossier, $from, $to, $scope);

        $byAccount = [];
        foreach ($this->lines($dossier, $from, $to, $scope)->getQuery()->getResult() as $line) {
            $byAccount[(int) $line->getAccount()->getId()][] = $line;
        }

        $accounts = [];
        foreach ($this->accounts->findPlan($dossier) as $account) {
            $id = (int) $account->getId();
            if (!isset($before[$id]) && !isset($within[$id])) {
                continue;
            }
            $opening = $before[$id] ?? Totals::zero();
            $accounts[] = new GeneralLedgerAccount(
                $account,
                $opening,
                self::withBalances($byAccount[$id] ?? [], $opening->balance()),
                $opening->plus($within[$id] ?? Totals::zero()),
            );
        }

        return $accounts;
    }

    /**
     * @param array{0: string, 1: object|null}|null $narrow a condition, and the object it names as `:one`
     *
     * @return array<int, Totals>
     */
    private function totalsBy(string $field, Dossier $dossier, ?\DateTimeImmutable $from, \DateTimeImmutable $to, EntryScope $scope, ?array $narrow = null): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select(\sprintf('IDENTITY(%s) AS id, SUM(l.debit) AS debit, SUM(l.credit) AS credit', $field))
            ->from(EntryLine::class, 'l')
            ->join('l.entry', 'e')
            ->groupBy($field);
        $this->narrow($query, $dossier, $from, $to, $scope);
        if (null !== $narrow) {
            $query->andWhere($narrow[0]);
            if (null !== $narrow[1]) {
                $query->setParameter('one', $narrow[1]);
            }
        }

        $totals = [];
        foreach ($query->getQuery()->getScalarResult() as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $totals[self::integer($row['id'] ?? null)] = new Totals(Money::cents(self::integer($row['debit'] ?? null)), Money::cents(self::integer($row['credit'] ?? null)));
        }

        return $totals;
    }

    /** The lines of the window with their entry, journal, exercise, account and third party, in date order. */
    private function lines(Dossier $dossier, ?\DateTimeImmutable $from, \DateTimeImmutable $to, EntryScope $scope): QueryBuilder
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('l', 'e', 'j', 'x', 't', 'a')
            ->from(EntryLine::class, 'l')
            ->join('l.entry', 'e')
            ->join('l.account', 'a')
            ->join('e.journal', 'j')
            ->join('e.exercise', 'x')
            ->leftJoin('l.thirdParty', 't')
            ->orderBy('e.entryDate', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->addOrderBy('l.position', 'ASC');
        $this->narrow($query, $dossier, $from, $to, $scope);

        return $query;
    }

    private function narrow(QueryBuilder $query, Dossier $dossier, ?\DateTimeImmutable $from, \DateTimeImmutable $to, EntryScope $scope): void
    {
        $query->andWhere('l.dossier = :dossier')
            ->andWhere('e.entryDate <= :to')
            ->setParameter('dossier', $dossier)
            ->setParameter('to', $to->format('Y-m-d'));
        if (null !== $from) {
            $query->andWhere('e.entryDate >= :from')->setParameter('from', $from->format('Y-m-d'));
        }
        if (EntryScope::Validated === $scope) {
            $query->andWhere('e.state = :validated')->setParameter('validated', EntryState::Validated);
        }
    }

    /**
     * @param iterable<mixed> $lines
     *
     * @return list<LedgerMovement>
     */
    private static function withBalances(iterable $lines, Money $balance): array
    {
        $movements = [];
        foreach ($lines as $line) {
            \assert($line instanceof EntryLine);
            $balance = $balance->plus($line->getDebit())->minus($line->getCredit());
            $movements[] = new LedgerMovement($line, $balance);
        }

        return $movements;
    }

    /** An aggregate as the database answers it (a numeric string, an int, null for no line). */
    private static function integer(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && 1 === preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }
        if (null === $value) {
            return 0;
        }

        throw new \UnexpectedValueException('An aggregate of cents is a whole number.');
    }
}
