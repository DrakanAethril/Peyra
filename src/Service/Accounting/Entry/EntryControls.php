<?php

declare(strict_types=1);

namespace App\Service\Accounting\Entry;

use App\Entity\Accounting\Account;
use App\Enum\Accounting\AccountNature;
use App\Enum\Accounting\AssistanceLevel;
use App\Enum\Accounting\JournalType;
use App\Service\Accounting\Ledger\EntryDraft;
use App\Service\Shared\Assistance\Assistance;
use App\Service\Shared\FrenchFormat;

/**
 * The controls of an entry typed in the grid (lot 1, step 1.4; design-ux.md § 3.2, « Saisie par
 * journal »), beyond what EntryRules refuses whatever the level (balance, closed period, unknown
 * account): an account unusual for the journal, a date outside the period being entered or after
 * the dossier's date, a document already entered in the journal. They say a rule, never the answer
 * a mission expects (design-ux.md § 6.1). At level A they warn, at G and B they stop the entry
 * before the brouillard, at level 0 none runs. A pure function over primitives.
 */
final class EntryControls
{
    /**
     * @param callable(string): ?Account $accountOf   an account of the dossier by number
     * @param callable(string): ?string  $documentUse what already carries this document in the journal
     *                                                (« ACH-2026-00007 »), or null
     *
     * @return list<EntryControl>
     */
    public static function check(
        EntryDraft $draft,
        JournalType $journalType,
        ?string $counterpart,
        \DateTimeImmutable $periodStart,
        \DateTimeImmutable $periodEnd,
        \DateTimeImmutable $dossierDate,
        AssistanceLevel $level,
        callable $accountOf,
        callable $documentUse,
    ): array {
        $assistance = Assistance::at($level);
        if (!$assistance->runsControls()) {
            return [];
        }
        $blocking = $assistance->blocks();
        $controls = [];

        if (null !== $draft->date) {
            $day = $draft->date->setTime(0, 0);
            if ($day < $periodStart->setTime(0, 0) || $day > $periodEnd->setTime(0, 0)) {
                $controls[] = new EntryControl('date', \sprintf(
                    'Le %s n’est pas en %s : l’écriture ira en %s.',
                    FrenchFormat::date($day),
                    FrenchFormat::period($periodStart),
                    FrenchFormat::period($day),
                ), $blocking);
            } elseif ($day > $dossierDate->setTime(0, 0)) {
                $controls[] = new EntryControl('date', \sprintf('Cette date est postérieure à la date du dossier (%s).', FrenchFormat::date($dossierDate)), $blocking);
            }
        }

        $document = trim($draft->document);
        if ('' !== $document) {
            $use = $documentUse($document);
            if (null !== $use) {
                $controls[] = new EntryControl('document', \sprintf('La pièce %s est déjà saisie dans ce journal (%s). Vérifiez qu’il ne s’agit pas d’un doublon.', $document, $use), $blocking);
            }
        }

        foreach ($draft->lines as $index => $line) {
            if ($line->isBlank()) {
                continue;
            }
            $account = $accountOf(trim($line->account));
            if (null !== $account && self::isUnusual($journalType, $account, $counterpart)) {
                $controls[] = new EntryControl('line.'.$index.'.account', \sprintf(
                    'Le compte %s (%s) est inhabituel dans un %s.',
                    $account->getNumber(),
                    mb_strtolower($account->getNature()->label()),
                    $journalType->phrase(),
                ), $blocking);
            }
        }

        return $controls;
    }

    /**
     * An account a journal does not usually carry: a revenue or a customer in purchases, an expense
     * or a supplier in sales, a bank or cash account in either (the payment has its own journal), an
     * expense or a revenue in the à-nouveaux (they carry the balance sheet), another bank or cash
     * account in a bank or cash journal (a transfer goes through 58, « virements internes »).
     */
    public static function isUnusual(JournalType $journalType, Account $account, ?string $counterpart): bool
    {
        $nature = $account->getNature();
        $treasury = AccountNature::Bank === $nature || AccountNature::Cash === $nature;

        return match ($journalType) {
            JournalType::Purchases => 7 === $account->getClassDigit() || AccountNature::Customer === $nature || $treasury,
            JournalType::Sales => 6 === $account->getClassDigit() || AccountNature::Supplier === $nature || $treasury,
            JournalType::Opening => \in_array($account->getClassDigit(), [6, 7], true),
            JournalType::Bank, JournalType::Cash => $treasury && $account->getNumber() !== $counterpart,
            JournalType::General => false,
        };
    }
}
