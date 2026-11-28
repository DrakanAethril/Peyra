<?php

declare(strict_types=1);

namespace App\Service\Mission\AnswerKey;

use App\Entity\Mission\MissionAnswerKey;

/**
 * The answer key's rules, pure (step 2.9b): an entry's fingerprint, which entries of the teacher's
 * copy the answer key keeps (those its starting state did not hold), and the tolerances read from
 * the form. The recorded shape is MissionAnswerKey's.
 *
 * @phpstan-import-type ExpectedEntry from MissionAnswerKey
 *
 * @phpstan-type EntryRow array{journal: string, date: string, document: string, label: string, state: string, lines: list<array{account: string, thirdParty: ?string, label: string, debit: int, credit: int}>}
 */
final class AnswerKeyReading
{
    public const int GROUPS_MAX = 30;

    /**
     * What an entry says, as one string: its state, journal, date, pièce, label and lines in their
     * order - never its id nor its number, so a copy of it (a restore point, a distribution) reads the
     * same.
     *
     * @param EntryRow $entry
     */
    public static function fingerprint(array $entry): string
    {
        $lines = array_map(static fn (array $line): array => [$line['account'], $line['thirdParty'], $line['label'], $line['debit'], $line['credit']], $entry['lines']);

        return sha1(json_encode([$entry['state'], $entry['journal'], $entry['date'], $entry['document'], $entry['label'], $lines], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
    }

    /**
     * The entries of the copy the answer key keeps: each one whose fingerprint the baseline does not
     * hold (a baseline fingerprint answers for one entry only). A line keeps the `optional` mark it had
     * in the previous record when its entry is the same (same fingerprint) - recording again does
     * not lose the tolerances of what did not move.
     *
     * @param list<EntryRow>      $entries  the copy's entries, in their order
     * @param list<string>        $baseline
     * @param list<ExpectedEntry> $previous
     *
     * @return list<ExpectedEntry>
     */
    public static function expected(array $entries, array $baseline, array $previous): array
    {
        $left = [];
        foreach ($baseline as $fingerprint) {
            $left[$fingerprint] = ($left[$fingerprint] ?? 0) + 1;
        }
        $optional = [];
        foreach ($previous as $entry) {
            foreach ($entry['lines'] as $j => $line) {
                if ($line['optional']) {
                    $optional[$entry['fingerprint']][$j] = true;
                }
            }
        }
        $expected = [];
        foreach ($entries as $entry) {
            $fingerprint = self::fingerprint($entry);
            if (($left[$fingerprint] ?? 0) > 0) {
                --$left[$fingerprint];
                continue;
            }
            $lines = [];
            foreach ($entry['lines'] as $j => $line) {
                $lines[] = $line + ['optional' => isset($optional[$fingerprint][$j])];
            }
            $expected[] = ['fingerprint' => $fingerprint, 'journal' => $entry['journal'], 'date' => $entry['date'], 'document' => $entry['document'], 'label' => $entry['label'], 'state' => $entry['state'], 'lines' => $lines];
        }

        return $expected;
    }

    /**
     * The groups of equivalent accounts typed one per line (« 601000 607000 », commas, semicolons or
     * « = » between them): each number of 6 to 8 digits, at least two different ones a line.
     *
     * @return array{0: list<list<string>>, 1: ?string} the groups, and the fault that refuses them
     */
    public static function equivalentAccounts(string $typed): array
    {
        $groups = [];
        $rank = 0;
        foreach (preg_split('/\R/', $typed) ?: [] as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }
            ++$rank;
            $numbers = [];
            foreach (preg_split('/[\s,;=]+/', $line, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $number) {
                if (1 !== preg_match('/^\d{6,8}$/', $number)) {
                    return [[], \sprintf('Ligne %d : « %s » n’est pas un numéro de compte (6 à 8 chiffres).', $rank, $number)];
                }
                $numbers[$number] = true;
            }
            if (\count($numbers) < 2) {
                return [[], \sprintf('Ligne %d : nommez au moins deux comptes admis l’un pour l’autre.', $rank)];
            }
            $groups[] = array_map('strval', array_keys($numbers));
        }
        if (\count($groups) > self::GROUPS_MAX) {
            return [[], \sprintf('Au plus %d lignes de comptes équivalents.', self::GROUPS_MAX)];
        }

        return [$groups, null];
    }

    /** @return array{0: int, 1: ?string} the window in days, and the fault that refuses it */
    public static function dateWindow(string $typed): array
    {
        $typed = trim($typed);
        if ('' === $typed) {
            return [0, null];
        }
        if (1 !== preg_match('/^\d{1,2}$/', $typed) || (int) $typed > MissionAnswerKey::DATE_WINDOW_MAX) {
            return [0, \sprintf('Un nombre de jours, de 0 à %d.', MissionAnswerKey::DATE_WINDOW_MAX)];
        }

        return [(int) $typed, null];
    }

    /**
     * The entries with the lines ticked « facultative » (`<entry>.<line>` keys) and no other.
     *
     * @param list<ExpectedEntry> $entries
     * @param list<string>        $ticked
     *
     * @return list<ExpectedEntry>
     */
    public static function withOptional(array $entries, array $ticked): array
    {
        foreach ($entries as $i => $entry) {
            foreach ($entry['lines'] as $j => $line) {
                $entries[$i]['lines'][$j]['optional'] = \in_array($i.'.'.$j, $ticked, true);
            }
        }

        return $entries;
    }
}
