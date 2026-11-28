<?php

declare(strict_types=1);

namespace App\Service\Accounting\Ledger;

use App\Entity\Accounting\Account;
use App\Entity\Accounting\Journal;
use App\Entity\Dossier\Period;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Accounting\JournalType;
use App\Service\Accounting\Chart\AccountNumber;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;

/**
 * What makes an entry valid (conception § 5) - the one reading the Ledger applies to every draft,
 * change and validation, whoever asks (the grid, a module through AccountingGateway, the
 * demonstration). The dossier is reached through four callables; every fault is named under its
 * key (EntryDraft), the draft's texts come back normalized.
 *
 * - a journal of the dossier, a date inside an exercise that is not closed, in a period that is not
 *   closed; an entry of the à-nouveaux journal is dated the first day of its exercise (step 1.6);
 * - a document reference and a label;
 * - two lines at least (a blank line is skipped), each on an account of the plan, with a debit or a
 *   credit - one of them, positive;
 * - a line on a collective account names a third party kept on a collective account of a nature its
 *   kind accepts (its own, or another: 416000, 419100 for a customer); a line on a general account
 *   may name only the third party kept on that very account (455100 for a partner);
 * - a due date only on a lettrable account;
 * - total debit equals total credit: an entry is balanced or does not exist.
 */
final class EntryRules
{
    public const int DOCUMENT_MAX = 40;
    public const int LABEL_MAX = 200;

    /**
     * @param callable(string): ?Journal            $journalOf
     * @param callable(\DateTimeImmutable): ?Period $periodAt
     * @param callable(string): ?Account            $accountOf
     * @param callable(string): ?ThirdParty         $thirdPartyOf
     */
    public static function check(EntryDraft $draft, callable $journalOf, callable $periodAt, callable $accountOf, callable $thirdPartyOf): ?EntryFields
    {
        $draft->errors = [];
        $draft->journal = strtoupper(trim($draft->journal));
        $draft->document = self::text($draft->document);
        $draft->label = self::text($draft->label);

        $journal = null;
        if ('' === $draft->journal) {
            $draft->errors['journal'] = 'Choisissez le journal.';
        } else {
            $journal = $journalOf($draft->journal);
            if (null === $journal) {
                $draft->errors['journal'] = \sprintf('Le journal %s n’existe pas dans le dossier.', $draft->journal);
            }
        }

        $period = null;
        if (null === $draft->date) {
            $draft->errors['date'] = 'Indiquez la date de l’écriture.';
        } else {
            $period = $periodAt($draft->date);
            if (null === $period) {
                $draft->errors['date'] = \sprintf('Le %s n’est dans aucun exercice du dossier.', FrenchFormat::date($draft->date));
            } elseif ($period->getExercise()->isClosed()) {
                $draft->errors['date'] = \sprintf('L’exercice %s est clos : il ne prend plus d’écriture.', $period->getExercise()->getCode());
            } elseif ($period->isClosed()) {
                $draft->errors['date'] = \sprintf('La période %s est close : elle ne prend plus d’écriture.', mb_strtolower($period->getLabel()));
            }
        }

        if (null !== $journal && null !== $period && !isset($draft->errors['date']) && JournalType::Opening === $journal->getType()
            && $draft->date->format('Y-m-d') !== $period->getExercise()->getStartsOn()->format('Y-m-d')) {
            $draft->errors['date'] = \sprintf('Les à-nouveaux se datent du premier jour de l’exercice : le %s.', FrenchFormat::date($period->getExercise()->getStartsOn()));
        }

        if ('' === $draft->document) {
            $draft->errors['document'] = 'Indiquez la pièce : son numéro ou sa référence.';
        } elseif (mb_strlen($draft->document) > self::DOCUMENT_MAX) {
            $draft->errors['document'] = \sprintf('Une référence de pièce tient en %d caractères.', self::DOCUMENT_MAX);
        }

        if ('' === $draft->label) {
            $draft->errors['label'] = 'Indiquez le libellé de l’écriture.';
        } elseif (mb_strlen($draft->label) > self::LABEL_MAX) {
            $draft->errors['label'] = \sprintf('Un libellé tient en %d caractères.', self::LABEL_MAX);
        }

        $lines = [];
        $debit = Money::zero();
        $credit = Money::zero();
        $counted = 0;
        foreach ($draft->lines as $index => $line) {
            if ($line->isBlank()) {
                continue;
            }
            ++$counted;
            $fields = self::line($draft, $index, $line, $accountOf, $thirdPartyOf);
            if (null !== $fields) {
                $lines[] = $fields;
            }
            if (!$line->debit->isNegative() && !$line->credit->isNegative()) {
                $debit = $debit->plus($line->debit);
                $credit = $credit->plus($line->credit);
            }
        }

        if ($counted < 2) {
            $draft->errors['lines'] = 'Une écriture compte au moins deux lignes.';
        } elseif (!$debit->equals($credit)) {
            $gap = $debit->minus($credit);
            $draft->errors['lines'] = \sprintf(
                'L’écriture n’est pas équilibrée : écart de %s au %s.',
                FrenchFormat::amount($gap->abs()),
                $gap->isPositive() ? 'crédit' : 'débit',
            );
        }

        if ([] !== $draft->errors) {
            return null;
        }

        return new EntryFields($journal, $period, $lines);
    }

    /**
     * @param callable(string): ?Account    $accountOf
     * @param callable(string): ?ThirdParty $thirdPartyOf
     */
    private static function line(EntryDraft $draft, int $index, EntryLineDraft $line, callable $accountOf, callable $thirdPartyOf): ?EntryLineFields
    {
        $key = 'line.'.$index.'.';
        $line->account = AccountNumber::normalize($line->account);
        $line->thirdParty = strtoupper(trim($line->thirdParty));
        $line->label = self::text($line->label);
        $before = \count($draft->errors);

        $account = null;
        if ('' === $line->account) {
            $draft->errors[$key.'account'] = 'Indiquez le compte.';
        } else {
            $account = $accountOf($line->account);
            if (null === $account) {
                $draft->errors[$key.'account'] = \sprintf('Le compte %s n’est pas dans le plan du dossier.', $line->account);
            }
        }

        $thirdParty = null;
        if ('' !== $line->thirdParty) {
            $thirdParty = $thirdPartyOf($line->thirdParty);
            if (null === $thirdParty) {
                $draft->errors[$key.'thirdParty'] = \sprintf('Le tiers %s n’existe pas dans le dossier.', $line->thirdParty);
            }
        }
        if (null !== $account) {
            $fault = self::thirdPartyFault($account, $thirdParty, '' !== $line->thirdParty);
            if (null !== $fault) {
                $draft->errors[$key.'thirdParty'] = $fault;
            }
        }

        if (mb_strlen($line->label) > self::LABEL_MAX) {
            $draft->errors[$key.'label'] = \sprintf('Un libellé tient en %d caractères.', self::LABEL_MAX);
        }

        if ($line->debit->isNegative() || $line->credit->isNegative()) {
            $draft->errors[$key.'amount'] = 'Un montant est positif : portez-le dans l’autre colonne.';
        } elseif (!$line->debit->isZero() && !$line->credit->isZero()) {
            $draft->errors[$key.'amount'] = 'Une ligne porte un débit ou un crédit, pas les deux.';
        } elseif ($line->debit->isZero() && $line->credit->isZero()) {
            $draft->errors[$key.'amount'] = 'Indiquez un montant au débit ou au crédit.';
        }

        if (null !== $line->dueDate && null !== $account && !$account->isLettrable()) {
            $draft->errors[$key.'dueDate'] = \sprintf('Le compte %s n’est pas lettrable : une échéance se porte sur un compte de tiers.', $account->getNumber());
        }

        if (\count($draft->errors) > $before || null === $account) {
            return null;
        }

        return new EntryLineFields($account, $thirdParty, '' === $line->label ? $draft->label : $line->label, $line->debit, $line->credit, $line->dueDate);
    }

    /**
     * Why the line's third party does not go with its account, or null: a collective account asks
     * for a third party with an auxiliary code, of a kind that accepts the account's nature; a
     * general account takes only the third party kept on it.
     */
    public static function thirdPartyFault(Account $account, ?ThirdParty $thirdParty, bool $named): ?string
    {
        if ($account->isCollective()) {
            if (!$named) {
                return \sprintf('Le compte %s est collectif : indiquez le tiers.', $account->getNumber());
            }
            if (null === $thirdParty) {
                return null;
            }
            if (null === $thirdParty->getAuxiliaryCode()) {
                return null === $thirdParty->getAccount()
                    ? \sprintf('Le tiers %s n’est tenu sur aucun compte : il ne se saisit pas sur une écriture.', $thirdParty->getCode())
                    : \sprintf('Le tiers %s n’a pas de code auxiliaire : saisissez-le sur son compte %s.', $thirdParty->getCode(), $thirdParty->getAccount()->getNumber());
            }
            if (!\in_array($account->getNature(), $thirdParty->getKind()->accountNatures(), true)) {
                return \sprintf('Le tiers %s ne se saisit pas sur le compte %s. %s', $thirdParty->getCode(), $account->getNumber(), $thirdParty->getKind()->accountRule());
            }

            return null;
        }

        if (null !== $thirdParty && $thirdParty->getAccount() !== $account) {
            return null === $thirdParty->getAccount()
                ? \sprintf('Le tiers %s n’est tenu sur aucun compte : il ne se saisit pas sur une écriture.', $thirdParty->getCode())
                : \sprintf('Le tiers %s est tenu sur le compte %s, pas sur le %s.', $thirdParty->getCode(), $thirdParty->getAccount()->getNumber(), $account->getNumber());
        }

        return null;
    }

    private static function text(string $typed): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $typed));
    }
}
