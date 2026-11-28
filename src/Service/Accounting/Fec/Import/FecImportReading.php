<?php

declare(strict_types=1);

namespace App\Service\Accounting\Fec\Import;

use App\Enum\Accounting\AccountNature;
use App\Enum\Accounting\JournalType;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Service\Accounting\Chart\AccountNumber;
use App\Service\Accounting\Chart\AccountSearch;
use App\Service\Accounting\Fec\FecDocument;
use App\Service\Accounting\Fec\FecFinding;
use App\Service\Accounting\Fec\FecFormat;
use App\Service\Accounting\Ledger\EntryRules;
use App\Service\Shared\Money;
use App\Service\ThirdParty\AuxiliaryCode;
use App\Service\ThirdParty\ThirdPartyCode;

/**
 * What a FEC makes of a template (lot 1, step 1.9b) - a pure function over a file FecConformity
 * found well formed and balanced, and the exercise's first day. Each fault is a FecFinding on the
 * line of the file that carries it; a plan with one writes nothing.
 *
 * - **Accounts**: CompteNum, digits only, padded to six digits when shorter (« 401 » → « 401000 »,
 *   as the reference charts are copied), 6 to 8 digits, class 1 to 8. An account whose lines carry
 *   a CompAuxNum is collective - a third parties' account (class 4) only, and then every one of its
 *   lines names its third party; any other is not. Lettrable by default (AccountNumber) or when
 *   its lines are lettered. Its label is the first CompteLib.
 * - **Third parties**: one per CompAuxNum, on the collective of its first line, its kind read from
 *   that account (a supplier on 40…, a customer on 41…, an employee on 42…, anything else
 *   otherwise), named by its first CompAuxLib; a later line on an account its kind does not accept
 *   is a fault. The CompAuxNum is its auxiliary code when Peyra writes it so (AuxiliaryCode), else
 *   one is proposed; its code is its kind's next one (C0001, F0001…).
 * - **Journals**: JournalCode, 1 to 6 capitals or digits; its type read from its lines and name:
 *   à-nouveaux when every entry is dated the exercise's first day and its code (AN, RAN, ANO) or
 *   label (« nouveau ») says so - one journal at most -, bank (or cash) when one bank (cash) account
 *   is on every entry, which becomes its counterpart, purchases or sales by its code (AC…, HA… ;
 *   VE…, VT…, VN…) or label, else opérations diverses.
 * - **Entries**: one per JournalCode + EcritureNum, dated EcritureDate, pièce PieceRef (40
 *   characters at most), labelled by its first EcritureLib; each line on its net side (a debit and a
 *   credit on one line are netted, a negative amount goes to the other side), a line netting to
 *   zero is left out, and an entry keeps two lines at least. Labels are cut to EntryRules::LABEL_MAX.
 * - **Lettering**: the lines of one account (one third party on a collective) sharing an
 *   EcritureLet (case aside) are lettered together, in the order of the file's letters, when they
 *   hold debits and credits; a letter whose other lines are not in the file is left aside.
 */
final class FecImportReading
{
    private const array OPENING_CODES = ['AN', 'RAN', 'ANO', 'ANX'];

    public static function read(FecDocument $document, \DateTimeImmutable $exerciseStart): FecImportPlan
    {
        $columns = $document->signed() ? FecFormat::SIGNED_COLUMNS : FecFormat::COLUMNS;
        $findings = [];
        $zeroLines = 0;

        /** @var array<string, array{number: string, label: string, auxLine: ?int, bareLine: ?int}> $accounts by « #number » */
        $accounts = [];
        /** @var array<string, array{name: string, account: string, line: int}> $auxiliaries by the raw CompAuxNum, normalized */
        $auxiliaries = [];
        /** @var array<string, array{label: string, line: int, entries: list<int>}> $journals by code */
        $journals = [];
        /** @var list<array{journal: string, date: \DateTimeImmutable, document: string, label: string, line: int, lines: list<array{line: int, account: string, auxiliary: string, label: string, debit: Money, credit: Money, letter: string}>}> $entries */
        $entries = [];
        $current = null;

        foreach ($document->lines as [$line, $zones]) {
            $zone = array_combine($columns, $zones);
            $journal = mb_strtoupper(AccountNumber::normalize($zone['JournalCode']));
            if (!isset($journals[$journal])) {
                if (1 !== preg_match('/^[A-Z0-9]{1,6}$/', $journal)) {
                    $findings[] = new FecFinding($line, 'JournalCode', \sprintf('Un code de journal compte de 1 à 6 lettres ou chiffres, sans accent : « %s ».', $zone['JournalCode']));
                }
                $journals[$journal] = ['label' => self::cut($zone['JournalLib'], 80), 'line' => $line, 'entries' => []];
            }

            $number = self::accountNumber($zone['CompteNum']);
            $fault = AccountNumber::fault($number);
            if (null !== $fault) {
                $findings[] = new FecFinding($line, 'CompteNum', \sprintf('Le compte « %s » n’entre pas dans un plan de Peyra. %s', $zone['CompteNum'], $fault));
            }
            $accounts['#'.$number] ??= ['number' => $number, 'label' => self::cut($zone['CompteLib'], 200), 'auxLine' => null, 'bareLine' => null];
            $auxiliary = AuxiliaryCode::normalize($zone['CompAuxNum']);
            if ('' === $auxiliary) {
                $accounts['#'.$number]['bareLine'] ??= $line;
            } else {
                $accounts['#'.$number]['auxLine'] ??= $line;
                $auxiliaries[$auxiliary] ??= ['name' => self::cut('' === $zone['CompAuxLib'] ? $auxiliary : $zone['CompAuxLib'], 120), 'account' => $number, 'line' => $line];
            }

            [$debit, $credit] = self::sides($zone, $document->signed() ?? false);
            $key = $zone['JournalCode']."\0".$zone['EcritureNum'];
            if ($current !== $key) {
                $current = $key;
                $journals[$journal]['entries'][] = \count($entries);
                $entries[] = [
                    'journal' => $journal,
                    'date' => FecFormat::parseDay($zone['EcritureDate']) ?? throw new \LogicException('FecConformity reads the days first.'),
                    'document' => self::oneLine($zone['PieceRef']),
                    'label' => self::cut($zone['EcritureLib'], EntryRules::LABEL_MAX),
                    'line' => $line,
                    'lines' => [],
                ];
            }
            if ($debit->isZero() && $credit->isZero()) {
                ++$zeroLines;
                continue;
            }
            $entries[\count($entries) - 1]['lines'][] = [
                'line' => $line,
                'account' => $number,
                'auxiliary' => $auxiliary,
                'label' => self::cut($zone['EcritureLib'], EntryRules::LABEL_MAX),
                'debit' => $debit,
                'credit' => $credit,
                'letter' => mb_strtoupper(trim($zone['EcritureLet'])),
            ];
        }

        // Collective accounts: those whose lines carry auxiliary codes, every one of them.
        foreach ($accounts as $account) {
            if (null === $account['auxLine']) {
                continue;
            }
            if (!AccountNumber::mayBeCollective($account['number'])) {
                $findings[] = new FecFinding($account['auxLine'], 'CompAuxNum', \sprintf('Un compte auxiliaire se tient sur un compte de tiers de la classe 4 : le compte %s n’en est pas un.', $account['number']));
            } elseif (null !== $account['bareLine']) {
                $findings[] = new FecFinding($account['bareLine'], 'CompAuxNum', \sprintf('Les lignes du compte %s nomment leur compte auxiliaire : celle-ci doit nommer le sien.', $account['number']));
            }
        }

        $thirdParties = self::thirdParties($auxiliaries);
        $codes = [];
        foreach ($thirdParties as $auxiliary => $thirdParty) {
            $codes[$auxiliary] = $thirdParty->code;
        }

        $planEntries = [];
        $letterGroups = [];
        foreach ($entries as $index => $entry) {
            if (mb_strlen($entry['document']) > EntryRules::DOCUMENT_MAX) {
                $findings[] = new FecFinding($entry['line'], 'PieceRef', \sprintf('Une référence de pièce tient en %d caractères : « %s ».', EntryRules::DOCUMENT_MAX, $entry['document']));
            }
            if (\count($entry['lines']) < 2) {
                $findings[] = new FecFinding($entry['line'], null, 'Cette écriture n’a pas deux lignes qui portent un montant.');
            }
            $lines = [];
            foreach ($entry['lines'] as $position => $line) {
                $thirdParty = '' === $line['auxiliary'] ? null : $thirdParties[$line['auxiliary']];
                if (null !== $thirdParty && $thirdParty->account !== $line['account'] && AccountNumber::mayBeCollective($line['account'])
                    && !\in_array(AccountNature::of($line['account']), $thirdParty->kind->accountNatures(), true)) {
                    $findings[] = new FecFinding($line['line'], 'CompteNum', \sprintf(
                        'Le compte auxiliaire %s est %s, tenu sur le compte %s : il ne se porte pas sur le compte %s. %s',
                        $line['auxiliary'],
                        mb_strtolower($thirdParty->kind->label()),
                        $thirdParty->account,
                        $line['account'],
                        $thirdParty->kind->accountRule(),
                    ));
                }
                $lines[] = new FecImportLine($line['line'], $line['account'], $codes[$line['auxiliary']] ?? '', $line['label'], $line['debit'], $line['credit']);
                if ('' !== $line['letter']) {
                    $letterGroups[$line['account']."\0".$line['auxiliary']][$line['letter']][] = [$index, $position];
                }
            }
            $planEntries[] = new FecImportEntry($entry['journal'], $entry['date'], $entry['document'], $entry['label'], $lines, $entry['line']);
        }

        [$letterings, $lettersLeft, $lettered] = self::letterings($letterGroups, $planEntries);

        $planAccounts = [];
        foreach ($accounts as $account) {
            $planAccounts[] = new FecImportAccount(
                $account['number'],
                $account['label'],
                null !== $account['auxLine'],
                AccountNumber::isLettrableByDefault($account['number']) || isset($lettered['#'.$account['number']]),
            );
        }

        return new FecImportPlan(
            $planAccounts,
            self::journals($journals, $planEntries, $exerciseStart),
            array_values($thirdParties),
            $planEntries,
            $letterings,
            $zeroLines,
            $lettersLeft,
            self::sorted($findings),
        );
    }

    /** CompteNum without spaces, padded to six digits when shorter and made of digits. */
    private static function accountNumber(string $zone): string
    {
        $number = AccountNumber::normalize($zone);

        return 1 === preg_match('/^\d{1,5}$/', $number) ? str_pad($number, AccountNumber::COPIED_LENGTH, '0') : $number;
    }

    /**
     * The line's side: debit and credit netted, a negative amount on the other side.
     *
     * @param array<string, string> $zone
     *
     * @return array{Money, Money}
     */
    private static function sides(array $zone, bool $signed): array
    {
        if ($signed) {
            $amount = FecFormat::parseAmount($zone['Montant']) ?? Money::zero();
            $net = \in_array($zone['Sens'], ['D', '+1'], true) ? $amount : $amount->negated();
        } else {
            $net = (FecFormat::parseAmount($zone['Debit']) ?? Money::zero())->minus(FecFormat::parseAmount($zone['Credit']) ?? Money::zero());
        }

        return $net->isNegative() ? [Money::zero(), $net->negated()] : [$net, Money::zero()];
    }

    /**
     * @param array<string, array{name: string, account: string, line: int}> $auxiliaries
     *
     * @return array<string, FecImportThirdParty> by the CompAuxNum, normalized
     */
    private static function thirdParties(array $auxiliaries): array
    {
        $taken = [];
        foreach (array_keys($auxiliaries) as $auxiliary) {
            if (null === AuxiliaryCode::fault((string) $auxiliary)) {
                $taken[(string) $auxiliary] = true;
            }
        }
        $codes = [];
        $thirdParties = [];
        foreach ($auxiliaries as $auxiliary => $read) {
            $auxiliary = (string) $auxiliary;
            $kind = match (AccountNature::of($read['account'])) {
                AccountNature::Supplier => ThirdPartyKind::Supplier,
                AccountNature::Customer => ThirdPartyKind::Customer,
                AccountNature::Employee => ThirdPartyKind::Employee,
                default => ThirdPartyKind::Other,
            };
            $code = $auxiliary;
            if (null !== AuxiliaryCode::fault($auxiliary)) {
                $code = AuxiliaryCode::suggest($read['account'], $read['name'], static fn (string $candidate): bool => isset($taken[$candidate]));
                $taken[$code] = true;
            }
            $codes[] = $own = ThirdPartyCode::next($kind, $codes);
            $thirdParties[$auxiliary] = new FecImportThirdParty($own, $code, $read['name'], $kind, $read['account'], $read['line']);
        }

        return $thirdParties;
    }

    /**
     * @param array<string, array{label: string, line: int, entries: list<int>}> $journals
     * @param list<FecImportEntry>                                                $entries
     *
     * @return list<FecImportJournal>
     */
    private static function journals(array $journals, array $entries, \DateTimeImmutable $exerciseStart): array
    {
        $read = [];
        $opening = false;
        foreach ($journals as $code => $journal) {
            $code = (string) $code;
            $folded = AccountSearch::fold($journal['label']);
            $own = array_map(static fn (int $index): FecImportEntry => $entries[$index], $journal['entries']);
            $type = JournalType::General;
            $counterpart = null;
            $atStart = array_filter($own, static fn (FecImportEntry $entry): bool => $entry->date->format('Y-m-d') === $exerciseStart->format('Y-m-d'));
            if (!$opening && \count($atStart) === \count($own) && (\in_array($code, self::OPENING_CODES, true) || str_contains($folded, 'nouveau'))) {
                $type = JournalType::Opening;
                $opening = true;
            } elseif (null !== ($counterpart = self::commonAccount($own, AccountNature::Bank))) {
                $type = JournalType::Bank;
            } elseif (null !== ($counterpart = self::commonAccount($own, AccountNature::Cash))) {
                $type = JournalType::Cash;
            } elseif (str_starts_with($code, 'AC') || str_starts_with($code, 'HA') || str_contains($folded, 'achat')) {
                $type = JournalType::Purchases;
            } elseif (str_starts_with($code, 'VE') || str_starts_with($code, 'VT') || str_starts_with($code, 'VN') || str_contains($folded, 'vente')) {
                $type = JournalType::Sales;
            }
            $read[] = new FecImportJournal($code, $journal['label'], $type, $counterpart, $journal['line']);
        }

        return $read;
    }

    /**
     * The first account of that nature found on every entry of a journal, or null.
     *
     * @param list<FecImportEntry> $entries
     */
    private static function commonAccount(array $entries, AccountNature $nature): ?string
    {
        $common = null;
        foreach ($entries as $entry) {
            $found = [];
            foreach ($entry->lines as $line) {
                if (AccountNature::of($line->account) === $nature && !\in_array($line->account, $found, true)) {
                    $found[] = $line->account;
                }
            }
            $common = null === $common ? $found : array_values(array_intersect($common, $found));
            if ([] === $common) {
                return null;
            }
        }

        return $common[0] ?? null;
    }

    /**
     * The groups to letter, in the order of the keys met and of their letters (« A » before « B »,
     * « Z » before « AA »); a group without both a debit and a credit is left aside.
     *
     * @param array<string, array<string, list<array{int, int}>>> $groups by account and auxiliary code, then letter
     * @param list<FecImportEntry>                                $entries
     *
     * @return array{list<list<array{int, int}>>, int, array<string, true>} the groups, how many were left aside, the accounts lettered (by « #number »)
     */
    private static function letterings(array $groups, array $entries): array
    {
        $kept = [];
        $left = 0;
        $lettered = [];
        foreach ($groups as $key => $byLetter) {
            uksort($byLetter, static fn (string|int $a, string|int $b): int => [\strlen((string) $a), (string) $a] <=> [\strlen((string) $b), (string) $b]);
            foreach ($byLetter as $members) {
                $debit = false;
                $credit = false;
                foreach ($members as [$entry, $line]) {
                    $debit = $debit || $entries[$entry]->lines[$line]->debit->isPositive();
                    $credit = $credit || $entries[$entry]->lines[$line]->credit->isPositive();
                }
                if (!$debit || !$credit) {
                    ++$left;
                    continue;
                }
                $kept[] = $members;
                $lettered['#'.explode("\0", (string) $key)[0]] = true;
            }
        }

        return [$kept, $left, $lettered];
    }

    /**
     * @param list<FecFinding> $findings
     *
     * @return list<FecFinding>
     */
    private static function sorted(array $findings): array
    {
        usort($findings, static fn (FecFinding $a, FecFinding $b): int => ($a->line ?? \PHP_INT_MAX) <=> ($b->line ?? \PHP_INT_MAX));

        return $findings;
    }

    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function cut(string $text, int $length): string
    {
        return mb_substr(self::oneLine($text), 0, $length);
    }
}
