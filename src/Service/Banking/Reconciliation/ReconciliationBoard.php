<?php

declare(strict_types=1);

namespace App\Service\Banking\Reconciliation;

use App\Entity\Accounting\Account;
use App\Entity\Accounting\EntryLine;
use App\Entity\Banking\BankPointing;
use App\Entity\Banking\BankStatement;
use App\Entity\Banking\BankStatementLine;
use App\Enum\Accounting\JournalType;
use App\Repository\Banking\BankRuleRepository;
use App\Repository\Banking\BankStatementRepository;
use App\Service\Accounting\Ledger\EntryScope;
use App\Service\Accounting\Ledger\LedgerReader;
use App\Service\Accounting\Lettering\LetterSequence;
use App\Service\Banking\Rule\BankRuleMatch;
use App\Service\Shared\Assistance\Assistance;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The reading of a statement's reconciliation (step 2.6a): the books' movements it may point - the
 * entries on the statement's bank account in its exercise up to its last day, the brouillard
 * included, but the à-nouveaux (the opening balance is the last reconciled one) and the entries an
 * earlier statement pointed -, each with this statement's letter; the état de rapprochement
 * (ReconciliationRules::report()); the proposals of level G; the assignment rule of each open
 * line (G and B). Pointings reads the same movements under its lock. Inside the dossier's context.
 */
final readonly class ReconciliationBoard
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LedgerReader $ledger,
        private BankStatementRepository $statements,
        private BankRuleRepository $rules,
    ) {
    }

    public function view(BankStatement $statement, Assistance $assistance): ReconciliationView
    {
        $account = self::account($statement);
        $books = $this->books($statement);
        $report = $this->report($statement, $books);
        $proposals = [];
        $proposedLines = [];
        $proposedEntries = [];
        if ($assistance->proposesBankPointing() && !$statement->isReconciled()) {
            $proposals = ReconciliationRules::proposals(
                array_map(self::lineItem(...), array_values(array_filter($statement->getLines(), static fn (BankStatementLine $line): bool => null === $line->getLetter()))),
                array_map(static fn (BookItem $item): ReconciliationItem => $item->item(), array_values(array_filter($books, static fn (BookItem $item): bool => null === $item->letter))),
            );
            $letters = [];
            foreach ($statement->getLines() as $line) {
                $letters[] = $line->getLetter();
            }
            foreach ($books as $item) {
                $letters[] = $item->letter;
            }
            $used = array_values(array_filter($letters, static fn (?string $letter): bool => null !== $letter));
            foreach ($proposals as $pair) {
                $letter = LetterSequence::next($used);
                $used[] = $letter;
                $proposedLines[$pair['statement']] = $letter;
                $proposedEntries[$pair['entry']] = $letter;
            }
        }
        $rules = [];
        if ($assistance->proposesBankEntry()) {
            $all = $this->rules->findInOrder($statement->getDossier());
            foreach ($statement->getLines() as $line) {
                $rule = null === $line->getLetter() ? BankRuleMatch::first($all, $line->getLabel()) : null;
                if (null !== $rule) {
                    $rules[(int) $line->getId()] = $rule;
                }
            }
        }
        $previous = $this->statements->findPrevious($statement);

        return new ReconciliationView(
            $statement,
            $account,
            $previous,
            $this->statements->findNext($statement),
            $statement->getLines(),
            $books,
            $report,
            $proposals,
            $proposedLines,
            $proposedEntries,
            $rules,
            $this->openingNote($statement, $previous),
        );
    }

    /**
     * The movements of the statement's account it may point, by entry, in date order.
     *
     * @return list<BookItem>
     */
    public function books(BankStatement $statement): array
    {
        $account = self::account($statement);
        $from = $this->ledger->exerciseStart($statement->getDossier(), $statement->getEndsOn());
        $query = $this->entityManager->createQueryBuilder()
            ->select('l', 'e')
            ->from(EntryLine::class, 'l')
            ->join('l.entry', 'e')
            ->join('e.journal', 'j')
            ->andWhere('l.account = :account')->setParameter('account', $account)
            ->andWhere('e.entryDate <= :to')->setParameter('to', $statement->getEndsOn()->format('Y-m-d'))
            ->andWhere('j.type <> :opening')->setParameter('opening', JournalType::Opening->value)
            ->orderBy('e.entryDate', 'ASC')->addOrderBy('e.id', 'ASC');
        if (null !== $from) {
            $query->andWhere('e.entryDate >= :from')->setParameter('from', $from->format('Y-m-d'));
        }
        $amounts = [];
        $entries = [];
        /** @var EntryLine $line */
        foreach ($query->getQuery()->getResult() as $line) {
            $entry = $line->getEntry();
            $id = (int) $entry->getId();
            $entries[$id] = $entry;
            $amounts[$id] = ($amounts[$id] ?? Money::zero())->plus($line->getDebit())->minus($line->getCredit());
        }
        $letters = [];
        $earlier = [];
        /** @var BankPointing $pointing */
        foreach ($this->entityManager->getRepository(BankPointing::class)->createQueryBuilder('p')
            ->join('p.statement', 's')
            ->andWhere('s.journal = :journal')->setParameter('journal', $statement->getJournal())
            ->getQuery()->getResult() as $pointing) {
            $id = (int) $pointing->getEntry()->getId();
            if ($pointing->getStatement() === $statement) {
                $letters[$id] = $pointing->getLetter();
            } elseif ($pointing->getStatement()->getNumber() < $statement->getNumber()) {
                $earlier[$id] = true;
            }
        }
        $items = [];
        foreach ($entries as $id => $entry) {
            if (!isset($earlier[$id])) {
                $items[] = new BookItem($entry, $amounts[$id], $letters[$id] ?? null);
            }
        }

        return $items;
    }

    /** @param list<BookItem> $books */
    public function report(BankStatement $statement, array $books): ReconciliationReport
    {
        $account = self::account($statement);
        $from = $this->ledger->exerciseStart($statement->getDossier(), $statement->getEndsOn());
        $earlier = Money::zero();
        foreach ($this->statements->findLatestFirst($statement->getDossier(), $statement->getJournal()) as $other) {
            if ($other->getNumber() >= $statement->getNumber() || (null !== $from && $other->getEndsOn() < $from)) {
                continue;
            }
            foreach ($other->getLines() as $line) {
                if (null === $line->getLetter()) {
                    $earlier = $earlier->plus($line->getAmount());
                }
            }
        }

        return ReconciliationRules::report(
            $this->ledger->accountBalance($account, $from, $statement->getEndsOn(), EntryScope::WithDrafts)->balance(),
            $statement->getClosingBalance(),
            array_map(self::lineItem(...), $statement->getLines()),
            array_map(static fn (BookItem $item): ReconciliationItem => $item->item(), $books),
            $earlier,
        );
    }

    public static function account(BankStatement $statement): Account
    {
        return $statement->getJournal()->getCounterpartAccount() ?? throw new \LogicException('A bank journal has its account.');
    }

    public static function lineItem(BankStatementLine $line): ReconciliationItem
    {
        return new ReconciliationItem((int) $line->getId(), $line->getDate(), $line->getAmount(), $line->getLetter());
    }

    /** « Le solde de départ correspond au dernier rapprochement » (design-ux.md § 4.5), or what differs. */
    private function openingNote(BankStatement $statement, ?BankStatement $previous): ?string
    {
        if (null === $previous) {
            return null;
        }
        if ($previous->getClosingBalance()->equals($statement->getOpeningBalance())) {
            return null;
        }

        return \sprintf('Le solde de départ (%s) ne reprend pas le solde final du relevé n° %d (%s).', FrenchFormat::amount($statement->getOpeningBalance()), $previous->getNumber(), FrenchFormat::amount($previous->getClosingBalance()));
    }
}
