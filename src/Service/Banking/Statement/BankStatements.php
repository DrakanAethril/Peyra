<?php

declare(strict_types=1);

namespace App\Service\Banking\Statement;

use App\Entity\Accounting\EntryLine;
use App\Entity\Accounting\Journal;
use App\Entity\Banking\BankPointing;
use App\Entity\Banking\BankStatement;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Accounting\JournalType;
use App\Enum\Audit\AuditAction;
use App\Enum\Dossier\AccessLevel;
use App\Repository\Accounting\JournalRepository;
use App\Repository\Banking\BankStatementRepository;
use App\Service\Accounting\Ledger\LedgerReader;
use App\Service\Audit\AuditTrail;
use App\Service\Banking\BankingRefused;
use App\Service\Dossier\Rights\DossierRights;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one door of the bank statements (step 2.6a; design-ux.md § 3.2, « Relevés »): a statement
 * imported whole from its CSV file (StatementCsv) into a bank journal - its number free, its days
 * covering no other statement of the journal, every line inside them - or not at all; deleted
 * while nothing of it is pointed. Each gesture is recorded in the dossier's trail. Inside the
 * dossier's context.
 */
final readonly class BankStatements
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private BankStatementRepository $statements,
        private JournalRepository $journals,
        private LedgerReader $ledger,
        private AuditTrail $auditTrail,
        private DossierRights $rights,
    ) {
    }

    /** @return list<Journal> the dossier's bank journals, each with its account */
    public function bankJournals(Dossier $dossier): array
    {
        return array_values(array_filter($this->journals->findByType($dossier, JournalType::Bank), static fn (Journal $journal): bool => null !== $journal->getCounterpartAccount()));
    }

    /**
     * What the import form proposes for a journal: the number after its last statement, the days
     * after it to the end of that month, its closing balance as the next opening one - for the first
     * statement, the month of the dossier's date and the account's balance in the books before it.
     *
     * @return array{number: int, from: \DateTimeImmutable, to: \DateTimeImmutable, opening: Money}
     */
    public function defaults(Journal $journal): array
    {
        $last = $this->statements->findLast($journal);
        if (null !== $last) {
            $from = $last->getEndsOn()->modify('+1 day');

            return ['number' => $last->getNumber() + 1, 'from' => $from, 'to' => $from->modify('last day of this month'), 'opening' => $last->getClosingBalance()];
        }
        $from = $journal->getDossier()->getDossierDate()->modify('first day of this month');

        return ['number' => 1, 'from' => $from, 'to' => $from->modify('last day of this month'), 'opening' => $this->booksBefore($journal, $from)];
    }

    /**
     * The statement read from its file, written whole; a refusal names every fault under its field
     * (`journal`, `number`, `from`, `to`, `file`, `line.<n>`) and writes nothing.
     */
    public function import(Journal $journal, int $number, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to, Money $opening, string $bytes, string $source, User|string $actor): BankStatement
    {
        $dossier = $journal->getDossier();
        $refusal = $this->rights->journalRefusal($journal, $actor, AccessLevel::Enter);
        if (null !== $refusal) {
            throw new BankingRefused($refusal, ['journal' => $refusal]);
        }
        $errors = [];
        if (JournalType::Bank !== $journal->getType() || null === $journal->getCounterpartAccount()) {
            $errors['journal'] = 'Choisissez un journal de banque.';
        }
        if ($number < 1) {
            $errors['number'] = 'Le numéro du relevé est un entier positif.';
        } elseif (null !== $this->statements->findOneByNumber($journal, $number)) {
            $errors['number'] = \sprintf('Le relevé n° %d du journal %s est déjà importé.', $number, $journal->getCode());
        }
        if (null === $from) {
            $errors['from'] = 'Le premier jour du relevé est obligatoire.';
        }
        if (null === $to) {
            $errors['to'] = 'Le dernier jour du relevé est obligatoire.';
        }
        if (null !== $from && null !== $to) {
            if ($to < $from) {
                $errors['to'] = 'Le dernier jour précède le premier.';
            } else {
                foreach ($this->statements->findLatestFirst($dossier, $journal) as $other) {
                    if ($other->getStartsOn() <= $to && $from <= $other->getEndsOn()) {
                        $errors['from'] = \sprintf('Le relevé n° %d couvre déjà des jours de cette période (du %s au %s).', $other->getNumber(), FrenchFormat::date($other->getStartsOn()), FrenchFormat::date($other->getEndsOn()));
                        break;
                    }
                }
            }
        }
        $read = StatementCsv::read($bytes);
        $errors += $read['errors'];
        foreach ($read['lines'] as $index => $line) {
            if (null !== $from && null !== $to && ($line['date'] < $from || $line['date'] > $to)) {
                $errors['line.'.($index + 1)] = \sprintf('Opération %d : le %s est hors de la période du relevé.', $index + 1, FrenchFormat::date($line['date']));
            }
        }
        if ([] !== $errors || null === $from || null === $to) {
            throw new BankingRefused((string) reset($errors), $errors);
        }

        $statement = new BankStatement($dossier, $journal, $number, $from, $to, $opening, $dossier->getDossierDate(), mb_substr($source, 0, 120));
        foreach ($read['lines'] as $line) {
            $statement->addLine($line['date'], $line['label'], $line['amount']);
        }
        $this->entityManager->persist($statement);
        $this->auditTrail->inDossier($dossier, AuditAction::BankStatementImported, $actor, \sprintf(
            '%s du journal %s importé (%s) : %d opérations, du %s au %s, solde final %s',
            $statement->getTitle(),
            $journal->getCode(),
            $statement->getSource(),
            \count($read['lines']),
            FrenchFormat::date($from),
            FrenchFormat::date($to),
            FrenchFormat::amount($statement->getClosingBalance()),
        ), ['journal' => $journal->getCode(), 'number' => $number]);
        $this->entityManager->flush();

        return $statement;
    }

    /** A statement nothing of which is pointed, deleted with its lines. */
    public function delete(BankStatement $statement, User|string $actor): void
    {
        $refusal = $this->rights->journalRefusal($statement->getJournal(), $actor, AccessLevel::Enter);
        if (null !== $refusal) {
            throw new BankingRefused($refusal);
        }
        if ($statement->isReconciled()) {
            throw new BankingRefused('Ce rapprochement est validé : rouvrez-le, puis dépointez tout avant de supprimer le relevé.');
        }
        $pointed = $this->entityManager->getRepository(BankPointing::class)->count(['statement' => $statement]);
        foreach ($statement->getLines() as $line) {
            if (null !== $line->getLetter()) {
                ++$pointed;
            }
        }
        if (0 < $pointed) {
            throw new BankingRefused('Des lignes de ce relevé sont pointées : dépointez-les avant de le supprimer.');
        }
        $this->auditTrail->inDossier($statement->getDossier(), AuditAction::BankStatementDeleted, $actor, \sprintf('%s du journal %s supprimé', $statement->getTitle(), $statement->getJournal()->getCode()), ['journal' => $statement->getJournal()->getCode(), 'number' => $statement->getNumber()]);
        foreach ($statement->getLines() as $line) {
            $this->entityManager->remove($line);
        }
        $this->entityManager->remove($statement);
        $this->entityManager->flush();
    }

    /** The bank account's balance in the books before `from` - its à-nouveaux included -, for a first statement. */
    public function booksBefore(Journal $journal, \DateTimeImmutable $from): Money
    {
        $account = $journal->getCounterpartAccount() ?? throw new \LogicException('A bank journal has its account.');
        $start = $this->ledger->exerciseStart($journal->getDossier(), $from);
        $balance = Money::zero();
        $query = $this->entityManager->createQueryBuilder()
            ->select('l', 'e')->from(EntryLine::class, 'l')->join('l.entry', 'e')->join('e.journal', 'j')
            ->andWhere('l.account = :account')->setParameter('account', $account)
            ->andWhere('e.entryDate < :from OR (e.entryDate = :from AND j.type = :opening)')
            ->setParameter('from', $from->format('Y-m-d'))->setParameter('opening', JournalType::Opening->value);
        if (null !== $start) {
            $query->andWhere('e.entryDate >= :start')->setParameter('start', $start->format('Y-m-d'));
        }
        /** @var EntryLine $line */
        foreach ($query->getQuery()->getResult() as $line) {
            $balance = $balance->plus($line->getDebit())->minus($line->getCredit());
        }

        return $balance;
    }
}
