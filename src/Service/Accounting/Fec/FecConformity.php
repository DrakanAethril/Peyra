<?php

declare(strict_types=1);

namespace App\Service\Accounting\Fec;

use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;

/**
 * The FEC's conformity check (lot 1, step 1.9a), a pure function over the file's bytes and name, in
 * the spirit of the administration's own « Test Compta Demat »: what A47 A-1 asks of the file's
 * form, and what makes its entries an accounting.
 *
 * - The name: « SirenFECAAAAMMJJ », the date being the exercise's closing day when it is known.
 * - The form: a first line naming the eighteen zones in order (or Montant and Sens for Debit and
 *   Credit), eighteen zones a line, the required zones filled, days « AAAAMMJJ », amounts with a
 *   decimal comma and no thousands separator, CompAuxNum with CompAuxLib, EcritureLet with DateLet,
 *   Montantdevise with Idevise.
 * - The entries (JournalCode + EcritureNum): their lines one after the other, one date each, each
 *   balanced, dated in the exercise when it is known; per journal, numbers without a gap (the
 *   figures closing EcritureNum); in the chronological order of their validation (ValidDate), after
 *   the reprise of the à-nouveaux that opens the file - the leading entries of the first journal
 *   and the first day; the whole file balanced.
 *
 * The import of a FEC (step 1.9b) asks less: no name (`$fileName` null) and no sequence
 * (`$sequence` false) - Peyra numbers the entries it validates itself, in its own order.
 */
final class FecConformity
{
    public static function check(string $bytes, ?string $fileName, ?\DateTimeImmutable $start = null, ?\DateTimeImmutable $end = null, bool $sequence = true): FecReport
    {
        $head = null === $fileName ? [] : self::name($fileName, $end);
        $document = FecReader::read($bytes);
        if (\is_string($document)) {
            return new FecReport([...$head, new FecFinding(null, null, $document)], 0, 0, Money::zero(), Money::zero());
        }
        $signed = $document->signed();
        if (null === $signed) {
            return new FecReport([...$head, new FecFinding(1, null, \sprintf(
                'La première ligne nomme les dix-huit zones dans l’ordre (%s) ; elle lit : %s.',
                implode(', ', FecFormat::COLUMNS),
                implode(', ', $document->header),
            ))], \count($document->lines), 0, Money::zero(), Money::zero(), $document->encoding, $document->separatorLabel());
        }
        $columns = $signed ? FecFormat::SIGNED_COLUMNS : FecFormat::COLUMNS;

        $findings = [];
        $debit = Money::zero();
        $credit = Money::zero();
        /** @var array<string, array{line: int, journal: string, number: string, date: string, valid: ?\DateTimeImmutable, debit: Money, credit: Money}> $entries */
        $entries = [];
        $previous = null;
        foreach ($document->lines as [$line, $zones]) {
            if (\count($zones) !== \count($columns)) {
                $findings[] = new FecFinding($line, null, \sprintf('La ligne compte %d zones au lieu de %d.', \count($zones), \count($columns)));
                $previous = null;
                continue;
            }
            $zone = array_combine($columns, $zones);
            foreach (FecFormat::REQUIRED as $name) {
                if ('' === $zone[$name]) {
                    $findings[] = new FecFinding($line, $name, 'Zone obligatoire vide.');
                }
            }
            $days = [];
            foreach (['EcritureDate', 'PieceDate', 'DateLet', 'ValidDate'] as $name) {
                $days[$name] = '' === $zone[$name] ? null : FecFormat::parseDay($zone[$name]);
                if ('' !== $zone[$name] && null === $days[$name]) {
                    $findings[] = new FecFinding($line, $name, \sprintf('Date attendue au format AAAAMMJJ, sans séparateur : « %s ».', $zone[$name]));
                }
            }
            $date = $days['EcritureDate'];
            if (null !== $date && null !== $start && null !== $end && ($date < $start || $date > $end)) {
                $findings[] = new FecFinding($line, 'EcritureDate', \sprintf('Date hors de l’exercice, qui va du %s au %s.', FrenchFormat::date($start), FrenchFormat::date($end)));
            }
            [$lineDebit, $lineCredit] = $signed ? self::signedAmount($zone, $line, $findings) : [self::amount($zone, 'Debit', $line, $findings), self::amount($zone, 'Credit', $line, $findings)];
            foreach ([['CompAuxNum', 'CompAuxLib'], ['EcritureLet', 'DateLet'], ['Montantdevise', 'Idevise']] as [$first, $second]) {
                if (('' === $zone[$first]) !== ('' === $zone[$second])) {
                    $findings[] = new FecFinding($line, '' === $zone[$first] ? $first : $second, \sprintf('%s et %s se remplissent ensemble.', $first, $second));
                }
            }
            $debit = $debit->plus($lineDebit);
            $credit = $credit->plus($lineCredit);

            if ('' === $zone['JournalCode'] || '' === $zone['EcritureNum']) {
                $previous = null;
                continue;
            }
            $key = $zone['JournalCode']."\0".$zone['EcritureNum'];
            if (!isset($entries[$key])) {
                $entries[$key] = ['line' => $line, 'journal' => $zone['JournalCode'], 'number' => $zone['EcritureNum'], 'date' => $zone['EcritureDate'], 'valid' => $days['ValidDate'], 'debit' => $lineDebit, 'credit' => $lineCredit];
            } else {
                if ($previous !== $key) {
                    $findings[] = new FecFinding($line, 'EcritureNum', \sprintf('Les lignes de l’écriture %s se suivent : elle reprend ici après d’autres écritures.', $zone['EcritureNum']));
                }
                if ($zone['EcritureDate'] !== $entries[$key]['date']) {
                    $findings[] = new FecFinding($line, 'EcritureDate', \sprintf('Une écriture n’a qu’une date : « %s » ici, « %s » sur sa première ligne.', $zone['EcritureDate'], $entries[$key]['date']));
                }
                $entries[$key]['debit'] = $entries[$key]['debit']->plus($lineDebit);
                $entries[$key]['credit'] = $entries[$key]['credit']->plus($lineCredit);
            }
            $previous = $key;
        }

        foreach ($entries as $entry) {
            if (!$entry['debit']->equals($entry['credit'])) {
                $findings[] = new FecFinding($entry['line'], null, \sprintf('L’écriture %s n’est pas équilibrée : %s au débit, %s au crédit.', $entry['number'], FrenchFormat::amount($entry['debit']), FrenchFormat::amount($entry['credit'])));
            }
        }
        if ($sequence) {
            $findings = [...$findings, ...self::order(array_values($entries))];
        }
        usort($findings, static fn (FecFinding $a, FecFinding $b): int => ($a->line ?? \PHP_INT_MAX) <=> ($b->line ?? \PHP_INT_MAX));
        if ($sequence) {
            $findings = [...$findings, ...self::numbering(array_values($entries))];
        }
        if ([] === $document->lines) {
            $findings[] = new FecFinding(null, null, 'Le fichier ne contient aucune écriture : il n’a que sa ligne d’en-tête.');
        }
        if (!$debit->equals($credit)) {
            $findings[] = new FecFinding(null, null, \sprintf('Le fichier n’est pas équilibré : %s au débit, %s au crédit.', FrenchFormat::amount($debit), FrenchFormat::amount($credit)));
        }

        return new FecReport([...$head, ...$findings], \count($document->lines), \count($entries), $debit, $credit, $document->encoding, $document->separatorLabel());
    }

    /** @return list<FecFinding> */
    private static function name(string $fileName, ?\DateTimeImmutable $end): array
    {
        $stem = pathinfo($fileName, \PATHINFO_FILENAME);
        if (1 !== preg_match('/^\d{9}FEC(\d{8})$/', $stem, $parts) || null === ($closing = FecFormat::parseDay($parts[1]))) {
            return [new FecFinding(null, null, \sprintf('Le fichier se nomme SirenFECAAAAMMJJ : les neuf chiffres du SIREN, « FEC », puis la date de clôture de l’exercice ; il se nomme « %s ».', $fileName))];
        }
        if (null !== $end && $closing->format('Ymd') !== $end->format('Ymd')) {
            return [new FecFinding(null, null, \sprintf('La date du nom du fichier, le %s, n’est pas celle de la clôture de l’exercice, le %s.', FrenchFormat::date($closing), FrenchFormat::date($end)))];
        }

        return [];
    }

    /**
     * @param array<string, string> $zone
     * @param list<FecFinding>      $findings
     */
    private static function amount(array $zone, string $name, int $line, array &$findings): Money
    {
        if ('' === $zone[$name]) {
            $findings[] = new FecFinding($line, $name, 'Zone obligatoire vide.');

            return Money::zero();
        }
        $amount = FecFormat::parseAmount($zone[$name]);
        if (null === $amount) {
            $findings[] = new FecFinding($line, $name, \sprintf('Montant attendu avec une virgule décimale et sans séparateur de milliers : « %s ».', $zone[$name]));
        }

        return $amount ?? Money::zero();
    }

    /**
     * @param array<string, string> $zone
     * @param list<FecFinding>      $findings
     *
     * @return array{Money, Money} the debit and the credit
     */
    private static function signedAmount(array $zone, int $line, array &$findings): array
    {
        $amount = self::amount($zone, 'Montant', $line, $findings);
        $sense = $zone['Sens'];
        if (!\in_array($sense, ['D', 'C', '+1', '-1'], true)) {
            $findings[] = new FecFinding($line, 'Sens', '' === $sense ? 'Zone obligatoire vide.' : \sprintf('Le sens s’écrit D ou C, ou +1 ou -1 : « %s ».', $sense));

            return [Money::zero(), Money::zero()];
        }

        return \in_array($sense, ['D', '+1'], true) ? [$amount, Money::zero()] : [Money::zero(), $amount];
    }

    /**
     * ValidDate never goes back, once the reprise of the à-nouveaux (the leading entries of the
     * first journal and the first day) is passed.
     *
     * @param list<array{line: int, journal: string, number: string, date: string, valid: ?\DateTimeImmutable, debit: Money, credit: Money}> $entries
     *
     * @return list<FecFinding>
     */
    private static function order(array $entries): array
    {
        $findings = [];
        $leading = true;
        $last = null;
        foreach ($entries as $entry) {
            if ($leading && $entry['journal'] === $entries[0]['journal'] && $entry['date'] === $entries[0]['date']) {
                continue;
            }
            $leading = false;
            if (null === $entry['valid']) {
                continue;
            }
            if (null !== $last && $entry['valid'] < $last) {
                $findings[] = new FecFinding($entry['line'], 'ValidDate', \sprintf('Les écritures se suivent dans l’ordre chronologique de leur validation : celle-ci, validée le %s, vient après une écriture validée le %s.', FrenchFormat::date($entry['valid']), FrenchFormat::date($last)));
                continue;
            }
            $last = $entry['valid'];
        }

        return $findings;
    }

    /**
     * Per journal, the numbers closing EcritureNum follow each other without a gap.
     *
     * @param list<array{line: int, journal: string, number: string, date: string, valid: ?\DateTimeImmutable, debit: Money, credit: Money}> $entries
     *
     * @return list<FecFinding>
     */
    private static function numbering(array $entries): array
    {
        $numbers = [];
        foreach ($entries as $entry) {
            if (1 === preg_match('/(\d+)$/', $entry['number'], $parts)) {
                $numbers[$entry['journal']][] = (int) $parts[1];
            }
        }
        $findings = [];
        foreach ($numbers as $journal => $list) {
            $list = array_values(array_unique($list));
            sort($list);
            for ($i = 1, $n = \count($list); $i < $n; ++$i) {
                if ($list[$i] - $list[$i - 1] > 1) {
                    $findings[] = new FecFinding(null, 'EcritureNum', $list[$i] - $list[$i - 1] > 2
                        ? \sprintf('Numérotation du journal %s : les numéros %d à %d manquent.', $journal, $list[$i - 1] + 1, $list[$i] - 1)
                        : \sprintf('Numérotation du journal %s : le numéro %d manque.', $journal, $list[$i - 1] + 1));
                }
            }
        }

        return $findings;
    }
}
