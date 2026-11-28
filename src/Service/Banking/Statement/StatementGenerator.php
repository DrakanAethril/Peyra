<?php

declare(strict_types=1);

namespace App\Service\Banking\Statement;

use App\Entity\Accounting\Journal;
use App\Entity\Banking\BankStatement;
use App\Entity\Identity\User;
use App\Service\Accounting\Ledger\EntryScope;
use App\Enum\Accounting\JournalType;
use App\Repository\Banking\PaymentRepository;
use App\Service\Accounting\Ledger\LedgerReader;
use App\Service\Banking\BankingRefused;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;

/**
 * The teacher's statement generator (step 2.6b; lot 2, « Banque »; design-ux.md § 2.4, Pièces ›
 * Générateur): the statement of a bank journal's month as the bank would send it, written from the
 * dossier itself - the account's movements in the books, drafts included, but those the teacher
 * leaves for a later statement (a cheque not yet debited), and the bills remitted for collection
 * the bank credits at their due date -, plus what only the bank knows: its fees, its agios and the
 * transfers nobody announced. The file is the CSV the import reads, handed to BankStatements, the
 * statements' one door: the student reconciles it like any other.
 */
final readonly class StatementGenerator
{
    public const string FEES_LABEL = 'FRAIS TENUE DE COMPTE';
    public const string INTEREST_LABEL = 'AGIOS ARRETE DE COMPTE';

    public function __construct(
        private LedgerReader $ledger,
        private PaymentRepository $payments,
        private BankStatements $statements,
    ) {
    }

    /** @return list<StatementRow> what the books say the bank will print between the two days, in date order */
    public function rows(Journal $journal, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $account = $journal->getCounterpartAccount();
        if (JournalType::Bank !== $journal->getType() || null === $account) {
            return [];
        }
        $rows = [];
        foreach ($this->ledger->movements($account, $from, $to, EntryScope::WithDrafts) as $movement) {
            $line = $movement->line;
            $entry = $line->getEntry();
            if (JournalType::Opening === $entry->getJournal()->getType()) {
                continue;
            }
            $rows[] = new StatementRow('l'.$line->getId(), $entry->getEntryDate(), self::bankWording($entry->getLabel()), $line->getDebit()->minus($line->getCredit()), trim(($entry->getNumberLabel() ?? 'Brouillard').' · '.$entry->getDocument()));
        }
        foreach ($this->payments->findBillsDueAtCollection($journal, $from, $to) as $bill) {
            $due = $bill->getDueOn() ?? $bill->getPaidOn();
            $rows[] = new StatementRow('b'.$bill->getId(), $due, self::bankWording('REMISE EFFET ENC '.$bill->getReference().' '.$bill->getThirdParty()->getName()), $bill->getAmount(), 'Effet '.$bill->getReference().' à l’encaissement');
        }
        usort($rows, static fn (StatementRow $one, StatementRow $other): int => $one->date <=> $other->date);

        return $rows;
    }

    /**
     * The statement generated and imported: the rows kept (by key), the fees and the agios on the
     * last day, the unexpected transfers. Refused - a field, or the import itself -, nothing is
     * written; the faults are named by field (`fees`, `interest`, `extra.<i>.<field>`, and the
     * import's own).
     *
     * @param list<string>                                                                        $keep
     * @param list<array{date: ?\DateTimeImmutable, label: string, amount: ?Money, error: ?string}> $extras
     */
    public function generate(Journal $journal, \DateTimeImmutable $from, \DateTimeImmutable $to, array $keep, Money $fees, Money $interest, array $extras, User|string $actor): BankStatement
    {
        $errors = [];
        if ($fees->isNegative()) {
            $errors['fees'] = 'Les frais sont un montant positif : le relevé les débite.';
        }
        if ($interest->isNegative()) {
            $errors['interest'] = 'Les agios sont un montant positif : le relevé les débite.';
        }
        $lines = [];
        foreach ($this->rows($journal, $from, $to) as $row) {
            if (\in_array($row->key, $keep, true)) {
                $lines[] = ['date' => $row->date, 'label' => $row->label, 'amount' => $row->amount];
            }
        }
        foreach ($extras as $i => $extra) {
            if ('' === $extra['label'] && null === $extra['date'] && null === $extra['amount'] && null === $extra['error']) {
                continue;
            }
            if (null === $extra['date']) {
                $errors['extra.'.$i.'.date'] = 'Indiquez le jour de l’opération.';
            } elseif ($extra['date'] < $from || $extra['date'] > $to) {
                $errors['extra.'.$i.'.date'] = \sprintf('Le jour tombe dans le relevé : du %s au %s.', FrenchFormat::date($from), FrenchFormat::date($to));
            }
            if ('' === $extra['label']) {
                $errors['extra.'.$i.'.label'] = 'Indiquez le libellé que la banque imprime.';
            }
            if (null !== $extra['error']) {
                $errors['extra.'.$i.'.amount'] = $extra['error'];
            } elseif (null === $extra['amount'] || $extra['amount']->isZero()) {
                $errors['extra.'.$i.'.amount'] = 'Indiquez le montant : positif au crédit du compte, négatif au débit.';
            }
            if (null !== $extra['date'] && null !== $extra['amount']) {
                $lines[] = ['date' => $extra['date'], 'label' => self::bankWording($extra['label']), 'amount' => $extra['amount']];
            }
        }
        if ($fees->isPositive()) {
            $lines[] = ['date' => $to, 'label' => self::FEES_LABEL, 'amount' => $fees->negated()];
        }
        if ($interest->isPositive()) {
            $lines[] = ['date' => $to, 'label' => self::INTEREST_LABEL, 'amount' => $interest->negated()];
        }
        if ([] === $lines && [] === $errors) {
            $errors['keep'] = 'Le relevé n’aurait aucune opération : gardez-en une, ou ajoutez des frais.';
        }
        if ([] !== $errors) {
            throw new BankingRefused((string) reset($errors), $errors);
        }
        // A stable sort: the lines of one day keep the books' order, the bank's own after them.
        usort($lines, static fn (array $one, array $other): int => $one['date'] <=> $other['date']);
        $defaults = $this->statements->defaults($journal);

        return $this->statements->import($journal, $defaults['number'], $from, $to, $defaults['opening'], StatementCsv::write($lines), \sprintf('releve-genere-%s-%02d.csv', mb_strtolower($journal->getCode()), $defaults['number']), $actor);
    }

    /** What a bank prints: capitals, no accents, 60 characters at most. */
    public static function bankWording(string $text): string
    {
        $plain = transliterator_transliterate('Any-Latin; Latin-ASCII; Upper()', $text);

        return mb_substr(trim(preg_replace('/\s+/', ' ', false === $plain ? mb_strtoupper($text) : $plain) ?? ''), 0, 60);
    }
}
