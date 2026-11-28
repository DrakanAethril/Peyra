<?php

declare(strict_types=1);

namespace App\Service\Banking\Reconciliation;

use App\Entity\Accounting\Entry;
use App\Entity\Banking\BankPointing;
use App\Entity\Banking\BankStatement;
use App\Entity\Banking\BankStatementLine;
use App\Entity\Identity\User;
use App\Enum\Accounting\AccountingModule;
use App\Enum\Audit\AuditAction;
use App\Enum\Dossier\AccessLevel;
use App\Repository\Banking\BankStatementRepository;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Service\Accounting\Entry\AssistanceLevels;
use App\Service\Accounting\Gateway\AccountingGateway;
use App\Service\Accounting\Gateway\EntryProposal;
use App\Service\Accounting\Ledger\EntryDraft;
use App\Service\Accounting\Ledger\EntryRefused;
use App\Service\Accounting\Lettering\LetterSequence;
use App\Service\Audit\AuditTrail;
use App\Service\Banking\BankingRefused;
use App\Service\Dossier\Rights\DossierRights;
use App\Service\Shared\Assistance\Assistance;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

/**
 * The one door of a statement's reconciliation (step 2.6a; design-ux.md § 4.5): pointing a
 * selection under the statement's next letter, unpointing a letter, pointing the pairs proposed
 * at level G, writing the entry a statement line asks for (through AccountingGateway, the module
 * Banque) and pointing it, validating the reconciliation and reopening it.
 *
 * Each gesture runs under a lock on the statement's row, reads its lines and the account's
 * movements again under it, is recorded in the dossier's trail and flushed in one transaction; a
 * refusal is a BankingRefused and writes nothing. A validated reconciliation is pointed no more
 * until it is reopened, which the next statement's validation forbids. A member of a group's
 * dossier points where the profile enters the bank journal, validates where it validates.
 */
final readonly class Pointings
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ReconciliationBoard $board,
        private BankStatementRepository $statements,
        private ThirdPartyRepository $thirdParties,
        private AccountingGateway $gateway,
        private AssistanceLevels $assistance,
        private AuditTrail $auditTrail,
        private DossierRights $rights,
    ) {
    }

    /**
     * The statement lines and the entries chosen pointed together, under the statement's next
     * letter (« Pointé A »).
     *
     * @param list<int> $lineIds
     * @param list<int> $entryIds
     */
    public function point(BankStatement $statement, array $lineIds, array $entryIds, User|string $actor): string
    {
        $this->refuse($statement, $actor, AccessLevel::Enter);

        return $this->transactional($statement, function (array $lines, array $books) use ($statement, $lineIds, $entryIds, $actor): string {
            [$chosenLines, $chosenBooks] = self::chosen($lines, $books, array_values(array_unique($lineIds)), array_values(array_unique($entryIds)));
            $refusal = ReconciliationRules::pointingRefusal(
                array_map(static fn (BankStatementLine $line): Money => $line->getAmount(), $chosenLines),
                array_map(static fn (BookItem $item): Money => $item->amount, $chosenBooks),
            );
            if (null !== $refusal) {
                throw new BankingRefused($refusal);
            }

            return $this->pointTogether($statement, $chosenLines, $chosenBooks, $lines, $books, $actor);
        });
    }

    /** A letter of the statement unpointed (« Dépointer »); answers how many movements it held. */
    public function unpoint(BankStatement $statement, string $letter, User|string $actor): int
    {
        $this->refuse($statement, $actor, AccessLevel::Enter);
        $letter = mb_strtoupper(trim($letter));

        return $this->transactional($statement, function (array $lines, array $books) use ($statement, $letter, $actor): int {
            $count = 0;
            foreach ($lines as $line) {
                if ($letter === $line->getLetter()) {
                    $line->pointingSet(null);
                    ++$count;
                }
            }
            foreach ($this->pointingsOf($statement, $letter) as $pointing) {
                $this->entityManager->remove($pointing);
                ++$count;
            }
            if (0 === $count) {
                throw new BankingRefused(\sprintf('Rien n’est pointé %s sur le %s.', $letter, mb_strtolower($statement->getTitle())));
            }
            $this->record($statement, AuditAction::BankLinesUnpointed, $actor, \sprintf('Pointage %s défait sur le %s du journal %s : %d mouvements', $letter, mb_strtolower($statement->getTitle()), $statement->getJournal()->getCode(), $count), ['letter' => $letter]);

            return $count;
        });
    }

    /**
     * The pairs proposed at level G pointed, each under its letter (« Pointer les propositions »).
     *
     * @return list<string> the letters given
     */
    public function pointProposals(BankStatement $statement, User|string $actor): array
    {
        $this->refuse($statement, $actor, AccessLevel::Enter);
        if (!Assistance::at($this->assistance->for($statement->getDossier()))->proposesBankPointing()) {
            throw new BankingRefused('Le pointage n’est proposé qu’au niveau « Guidé » : pointez vous-même chaque ligne.');
        }

        return $this->transactional($statement, function (array $lines, array $books) use ($statement, $actor): array {
            $pairs = ReconciliationRules::proposals(
                array_map(ReconciliationBoard::lineItem(...), $lines),
                array_map(static fn (BookItem $item): ReconciliationItem => $item->item(), $books),
            );
            $letters = [];
            foreach ($pairs as $pair) {
                [$chosenLines, $chosenBooks] = self::chosen($lines, $books, [$pair['statement']], [$pair['entry']]);
                // Each pair holds a statement line, which carries its letter: the next pair reads it as used.
                $letters[] = $this->pointTogether($statement, $chosenLines, $chosenBooks, $lines, $books, $actor);
            }
            if ([] === $letters) {
                throw new BankingRefused('Aucune ligne du relevé n’a d’écriture du même montant à trois jours près.');
            }

            return $letters;
        });
    }

    /**
     * The entry a statement line asks for (« Générer l'écriture »), written in the brouillard of the
     * statement's journal through the gateway - `account` a number or a third party's auxiliary
     * code, the VAT split off at `vatRate` -, then pointed with the line.
     */
    public function generate(BankStatement $statement, int $lineId, string $account, ?string $vatRate, string $label, User|string $actor): Entry
    {
        $this->refuse($statement, $actor, AccessLevel::Enter);
        $line = null;
        foreach ($statement->getLines() as $one) {
            if ($one->getId() === $lineId) {
                $line = $one;
            }
        }
        if (null === $line) {
            throw new BankingRefused('Cette ligne n’est pas sur le relevé : affichez-le de nouveau.');
        }
        if (null !== $line->getLetter()) {
            throw new BankingRefused(\sprintf('La ligne du %s est déjà pointée %s.', $line->getDate()->format('d/m'), $line->getLetter()));
        }
        $typed = mb_strtoupper(trim($account));
        if ('' === $typed) {
            throw new BankingRefused('Indiquez le compte de l’écriture : un numéro, ou le code auxiliaire d’un tiers.', ['account' => 'Le compte est obligatoire.']);
        }
        $number = $typed;
        $thirdParty = '';
        if (1 === preg_match('/[A-Z]/', $typed)) {
            $found = $this->thirdParties->findOneByAuxiliaryCode($statement->getDossier(), $typed);
            if (null === $found || null === $found->getAccount()) {
                throw new BankingRefused(\sprintf('Aucun tiers n’a le code auxiliaire %s.', $typed), ['account' => 'Code auxiliaire inconnu.']);
            }
            [$number, $thirdParty] = [$found->getAccount()->getNumber(), $found->getCode()];
        }
        $text = '' === trim($label) ? $line->getLabel() : trim($label);
        $draft = new EntryDraft(
            $statement->getJournal()->getCode(),
            $line->getDate(),
            \sprintf('RLV%d-%02d', $statement->getNumber(), $line->getPosition()),
            mb_substr($text, 0, 60),
            BankLineEntry::lines($line->getAmount(), ReconciliationBoard::account($statement)->getNumber(), $number, $thirdParty, $vatRate, mb_substr($text, 0, 60)),
        );
        try {
            $entry = $this->gateway->record($statement->getDossier(), new EntryProposal(AccountingModule::Banking, $draft, $statement->getTitle()), $actor);
        } catch (EntryRefused $refused) {
            $errors = $refused->errors;
            throw new BankingRefused((string) (reset($errors) ?: 'L’écriture est refusée.'), $errors);
        }
        $this->point($statement, [$lineId], [(int) $entry->getId()], $actor);

        return $entry;
    }

    /**
     * The reconciliation validated on the dossier's date. What stands against it (an état that
     * does not fall right, a line neither pointed nor posted) refuses it at G and B, and comes back
     * as a warning at A; level 0 says nothing.
     */
    public function validate(BankStatement $statement, User|string $actor): ?string
    {
        $this->refuse($statement, $actor, AccessLevel::Validate);
        $assistance = Assistance::at($this->assistance->for($statement->getDossier()));

        return $this->transactional($statement, function (array $lines, array $books) use ($statement, $assistance, $actor): ?string {
            $report = $this->board->report($statement, $books);
            $refusal = ReconciliationRules::validationRefusal($report);
            if (null !== $refusal && $assistance->blocksReconciliationGaps()) {
                throw new BankingRefused($refusal);
            }
            $statement->reconcile($statement->getDossier()->getDossierDate());
            $this->record($statement, AuditAction::BankReconciliationValidated, $actor, \sprintf('Rapprochement du %s du journal %s validé : solde rectifié %s', mb_strtolower($statement->getTitle()), $statement->getJournal()->getCode(), FrenchFormat::amount($report->rectifiedBooks())), ['gap' => $report->gap()->cents]);

            return null !== $refusal && $assistance->signalsReconciliationGaps() ? $refusal : null;
        }, false);
    }

    /** A validated reconciliation reopened (« Rouvrir le rapprochement »), while the next one is not validated. */
    public function reopen(BankStatement $statement, User|string $actor): void
    {
        $this->refuse($statement, $actor, AccessLevel::Validate, false);
        $next = $this->statements->findNext($statement);
        if (null !== $next && $next->isReconciled()) {
            throw new BankingRefused(\sprintf('Le rapprochement du relevé n° %d est validé : rouvrez-le d’abord.', $next->getNumber()));
        }
        if (!$statement->isReconciled()) {
            throw new BankingRefused('Ce rapprochement n’est pas validé.');
        }
        $this->transactional($statement, function () use ($statement, $actor): void {
            $statement->reopen();
            $this->record($statement, AuditAction::BankReconciliationReopened, $actor, \sprintf('Rapprochement du %s du journal %s rouvert', mb_strtolower($statement->getTitle()), $statement->getJournal()->getCode()));
        }, false);
    }

    /**
     * @param list<BankStatementLine> $chosenLines
     * @param list<BookItem>          $chosenBooks
     * @param list<BankStatementLine> $lines
     * @param list<BookItem>          $books
     */
    private function pointTogether(BankStatement $statement, array $chosenLines, array $chosenBooks, array $lines, array $books, User|string $actor): string
    {
        $used = [];
        foreach ($lines as $line) {
            $used[] = $line->getLetter();
        }
        foreach ($books as $item) {
            $used[] = $item->letter;
        }
        $letter = LetterSequence::next(array_values(array_filter($used, static fn (?string $one): bool => null !== $one)));
        foreach ($chosenLines as $line) {
            $line->pointingSet($letter);
        }
        foreach ($chosenBooks as $item) {
            $this->entityManager->persist(new BankPointing($statement, $item->entry, $letter));
        }
        $this->record($statement, AuditAction::BankLinesPointed, $actor, \sprintf(
            'Pointage %s sur le %s du journal %s : %d ligne%s du relevé, %d écriture%s, %s',
            $letter,
            mb_strtolower($statement->getTitle()),
            $statement->getJournal()->getCode(),
            \count($chosenLines),
            \count($chosenLines) > 1 ? 's' : '',
            \count($chosenBooks),
            \count($chosenBooks) > 1 ? 's' : '',
            FrenchFormat::amount(Money::sum(array_map(static fn (BookItem $item): Money => $item->amount, $chosenBooks))),
        ), ['letter' => $letter, 'lines' => array_map(static fn (BankStatementLine $line): int => (int) $line->getId(), $chosenLines), 'entries' => array_map(static fn (BookItem $item): int => (int) $item->entry->getId(), $chosenBooks)]);

        return $letter;
    }

    /**
     * @param list<BankStatementLine> $lines
     * @param list<BookItem>          $books
     * @param list<int>               $lineIds
     * @param list<int>               $entryIds
     *
     * @return array{list<BankStatementLine>, list<BookItem>}
     */
    private static function chosen(array $lines, array $books, array $lineIds, array $entryIds): array
    {
        $chosenLines = [];
        foreach ($lines as $line) {
            if (\in_array((int) $line->getId(), $lineIds, true)) {
                if (null !== $line->getLetter()) {
                    throw new BankingRefused(\sprintf('La ligne du %s est déjà pointée %s : dépointez-la d’abord.', $line->getDate()->format('d/m'), $line->getLetter()));
                }
                $chosenLines[] = $line;
            }
        }
        $chosenBooks = [];
        foreach ($books as $item) {
            if (\in_array((int) $item->entry->getId(), $entryIds, true)) {
                if (null !== $item->letter) {
                    throw new BankingRefused(\sprintf('L’écriture %s est déjà pointée %s : dépointez-la d’abord.', $item->entry->getDocument(), $item->letter));
                }
                $chosenBooks[] = $item;
            }
        }
        if (\count($chosenLines) !== \count($lineIds) || \count($chosenBooks) !== \count($entryIds)) {
            throw new BankingRefused('Une ligne choisie n’est plus à pointer sur ce relevé : affichez-le de nouveau.');
        }

        return [$chosenLines, $chosenBooks];
    }

    /** @return list<BankPointing> */
    private function pointingsOf(BankStatement $statement, string $letter): array
    {
        return $this->entityManager->getRepository(BankPointing::class)->findBy(['statement' => $statement, 'letter' => $letter]);
    }

    private function refuse(BankStatement $statement, User|string $actor, AccessLevel $wanted, bool $open = true): void
    {
        $refusal = $this->rights->journalRefusal($statement->getJournal(), $actor, $wanted);
        if (null !== $refusal) {
            throw new BankingRefused($refusal);
        }
        if ($open && $statement->isReconciled()) {
            throw new BankingRefused(\sprintf('Le rapprochement du %s est validé : rouvrez-le pour pointer de nouveau.', mb_strtolower($statement->getTitle())));
        }
    }

    /**
     * Locks the statement, reads its lines and the account's movements again, runs the gesture,
     * flushes and commits; a refusal rolls back before anything was persisted.
     *
     * @template T
     *
     * @param callable(list<BankStatementLine>, list<BookItem>): T $gesture
     *
     * @return T
     */
    private function transactional(BankStatement $statement, callable $gesture, bool $open = true): mixed
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $this->entityManager->lock($statement, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($statement);
            if ($open && $statement->isReconciled()) {
                throw new BankingRefused(\sprintf('Le rapprochement du %s est validé : rouvrez-le pour pointer de nouveau.', mb_strtolower($statement->getTitle())));
            }
            /** @var list<BankStatementLine> $lines */
            $lines = $this->entityManager->createQueryBuilder()
                ->select('l')->from(BankStatementLine::class, 'l')
                ->andWhere('l.statement = :statement')->setParameter('statement', $statement)
                ->orderBy('l.position', 'ASC')
                ->getQuery()->setHint(Query::HINT_REFRESH, true)->getResult();
            $result = $gesture($lines, $this->board->books($statement));
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $failure) {
            $connection->rollBack();
            throw $failure;
        }

        return $result;
    }

    /** @param array<string, mixed> $details */
    private function record(BankStatement $statement, AuditAction $action, User|string $actor, string $summary, array $details = []): void
    {
        $this->auditTrail->inDossier($statement->getDossier(), $action, $actor, $summary, ['statement' => $statement->getId()] + $details);
    }
}
