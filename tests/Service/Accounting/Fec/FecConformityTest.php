<?php

declare(strict_types=1);

namespace App\Tests\Service\Accounting\Fec;

use App\Service\Accounting\Fec\FecConformity;
use App\Service\Accounting\Fec\FecFinding;
use App\Service\Accounting\Fec\FecFormat;
use App\Service\Accounting\Fec\FecReport;
use PHPUnit\Framework\TestCase;

/**
 * The conformity check on files written by hand: the opening (AN, validated on the 15/01, after the
 * purchases - the reprise opens the file whatever its day of validation), two purchases, a payment
 * lettered. 18 416,24 on each side: 10 000,00 + 3 465,10 + 693,02 + 100,00 + 4 158,12.
 */
final class FecConformityTest extends TestCase
{
    private const string NAME = '999412736FEC20261231.txt';

    public function testAFileWrittenToTheRuleIsConform(): void
    {
        $report = $this->check($this->rows());

        self::assertSame([], $this->messages($report));
        self::assertTrue($report->isConform());
        self::assertSame(9, $report->lines);
        self::assertSame(4, $report->entries);
        self::assertSame(1_841_624, $report->debit->cents);
        self::assertSame(1_841_624, $report->credit->cents);
        self::assertSame('UTF-8', $report->encoding);
        self::assertSame('tabulation', $report->separator);
    }

    public function testTheNameIsTheSirenFecAndTheClosingDay(): void
    {
        self::assertStringContainsString('SirenFECAAAAMMJJ', $this->messages($this->check($this->rows(), 'fec-2026.txt'))[0]);
        self::assertStringContainsString('SirenFECAAAAMMJJ', $this->messages($this->check($this->rows(), '99941273FEC20261231.txt'))[0]);
        self::assertSame(['La date du nom du fichier, le 30/06/2026, n’est pas celle de la clôture de l’exercice, le 31/12/2026.'], $this->messages($this->check($this->rows(), '999412736FEC20260630.txt')));
        // Without a known exercise, any real day will do; the extension is free.
        self::assertTrue(FecConformity::check($this->text($this->rows()), '999412736FEC20260630.csv')->isConform());
    }

    public function testTheFirstLineNamesTheEighteenZonesInOrder(): void
    {
        $header = FecFormat::COLUMNS;
        [$header[0], $header[1]] = [$header[1], $header[0]];
        $report = $this->check($this->rows(), header: $header);

        self::assertCount(1, $report->findings);
        self::assertSame(1, $report->findings[0]->line);
        self::assertStringStartsWith('La première ligne nomme les dix-huit zones dans l’ordre', $report->findings[0]->message);
        // The case of the names is not read.
        self::assertTrue($this->check($this->rows(), header: array_map(strtoupper(...), FecFormat::COLUMNS))->isConform());
    }

    public function testEachLineIsEighteenZonesWithItsRequiredOnesFilledAndItsFormats(): void
    {
        $rows = $this->rows();
        $rows[1] = \array_slice($rows[1], 0, 17);
        $rows[2][5] = '';                // CompteLib
        $rows[3][3] = '2026-01-08';      // EcritureDate
        $rows[3][9] = '2026-01-08';      // PieceDate
        $rows[4][11] = '1 000,00';       // Debit

        // The opening loses its credit line: 10 000,00 left on the debit side; the purchase keeps its
        // credit (an amount not read counts nothing, and its debit was 0,00).
        self::assertSame([
            '2 · L’écriture AN-2026-00001 n’est pas équilibrée : 10 000,00 au débit, 0,00 au crédit.',
            '3 · La ligne compte 17 zones au lieu de 18.',
            '4 · CompteLib · Zone obligatoire vide.',
            '5 · EcritureDate · Date attendue au format AAAAMMJJ, sans séparateur : « 2026-01-08 ».',
            '5 · PieceDate · Date attendue au format AAAAMMJJ, sans séparateur : « 2026-01-08 ».',
            '5 · EcritureDate · Une écriture n’a qu’une date : « 2026-01-08 » ici, « 20260108 » sur sa première ligne.',
            '6 · Debit · Montant attendu avec une virgule décimale et sans séparateur de milliers : « 1 000,00 ».',
            'Le fichier n’est pas équilibré : 18 416,24 au débit, 8 416,24 au crédit.',
        ], $this->located($this->check($rows)));
    }

    public function testPairedZonesGoTogether(): void
    {
        $rows = $this->rows();
        $rows[4][7] = '';   // CompAuxLib without its name
        $rows[7][14] = '';  // EcritureLet without DateLet
        $rows[8][16] = '12,00';  // Montantdevise without Idevise

        self::assertSame([
            '6 · CompAuxLib · CompAuxNum et CompAuxLib se remplissent ensemble.',
            '9 · DateLet · EcritureLet et DateLet se remplissent ensemble.',
            '10 · Idevise · Montantdevise et Idevise se remplissent ensemble.',
        ], $this->located($this->check($rows)));
    }

    public function testAnEntryIsOneDayItsLinesInARowBalancedAndInTheExercise(): void
    {
        $rows = $this->rows();
        // The second purchase's credit line moved after the payment's lines.
        $moved = $rows[6];
        unset($rows[6]);
        $rows[] = $moved;
        $rows = array_values($rows);
        // The payment dated outside the exercise.
        $rows[6][3] = $rows[7][3] = '20270120';

        self::assertSame([
            '8 · EcritureDate · Date hors de l’exercice, qui va du 01/01/2026 au 31/12/2026.',
            '9 · EcritureDate · Date hors de l’exercice, qui va du 01/01/2026 au 31/12/2026.',
            '10 · EcritureNum · Les lignes de l’écriture ACH-2026-00002 se suivent : elle reprend ici après d’autres écritures.',
        ], $this->located($this->check($rows)));
    }

    public function testNumbersFollowEachOtherPerJournal(): void
    {
        $rows = $this->rows();
        $rows[5][2] = $rows[6][2] = 'ACH-2026-00005';

        self::assertSame(['EcritureNum · Numérotation du journal ACH : les numéros 2 à 4 manquent.'], $this->located($this->check($rows)));

        $rows[5][2] = $rows[6][2] = 'ACH-2026-00003';
        self::assertSame(['EcritureNum · Numérotation du journal ACH : le numéro 2 manque.'], $this->located($this->check($rows)));
    }

    public function testEntriesFollowTheChronologicalOrderOfTheirValidationAfterTheOpening(): void
    {
        $rows = $this->rows();
        // The second purchase validated before the first.
        $rows[5][15] = $rows[6][15] = '20260109';

        self::assertSame(['7 · ValidDate · Les écritures se suivent dans l’ordre chronologique de leur validation : celle-ci, validée le 09/01/2026, vient après une écriture validée le 10/01/2026.'], $this->located($this->check($rows)));
    }

    public function testMontantAndSensMayReplaceDebitAndCredit(): void
    {
        $rows = array_map(static function (array $row): array {
            $debit = '0,00' !== $row[11];
            $row[11] = $debit ? $row[11] : $row[12];
            $row[12] = $debit ? 'D' : 'C';

            return $row;
        }, $this->rows());
        $report = $this->check($rows, header: FecFormat::SIGNED_COLUMNS);
        self::assertSame([], $this->located($report));
        self::assertSame(1_841_624, $report->debit->cents);

        $rows[0][12] = '+1';
        $rows[1][12] = '-1';
        self::assertTrue($this->check($rows, header: FecFormat::SIGNED_COLUMNS)->isConform());
        $rows[2][12] = 'X';
        self::assertSame([
            '4 · Sens · Le sens s’écrit D ou C, ou +1 ou -1 : « X ».',
            '4 · L’écriture ACH-2026-00001 n’est pas équilibrée : 693,02 au débit, 4 158,12 au crédit.',
            'Le fichier n’est pas équilibré : 14 951,14 au débit, 18 416,24 au crédit.',
        ], $this->located($this->check($rows, header: FecFormat::SIGNED_COLUMNS)));
    }

    public function testThePipeIso885915AndAByteOrderMarkAreRead(): void
    {
        $text = $this->text($this->rows(), '|');
        $report = FecConformity::check((string) mb_convert_encoding($text, 'ISO-8859-15', 'UTF-8'), self::NAME, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'));
        self::assertTrue($report->isConform());
        self::assertSame('ISO-8859-15', $report->encoding);
        self::assertSame('|', $report->separator);

        self::assertTrue(FecConformity::check("\u{FEFF}".$this->text($this->rows()), self::NAME)->isConform());
    }

    public function testWhatIsNotAFecSaysSo(): void
    {
        self::assertSame(['Le fichier est vide.'], $this->messages(FecConformity::check("\r\n\r\n", self::NAME)));
        self::assertStringStartsWith('La première ligne doit nommer les zones', $this->messages(FecConformity::check("numero;intitule\r\n601000;Achats\r\n", self::NAME))[0]);
        self::assertSame(['Le fichier ne contient aucune écriture : il n’a que sa ligne d’en-tête.'], $this->messages($this->check([])));
    }

    /** @return list<list<string>> */
    private function rows(): array
    {
        $line = static fn (string $journal, string $label, string $number, string $date, string $account, string $accountLabel, string $auxiliary, string $piece, string $text, string $debit, string $credit, string $valid, string $letter = ''): array => [
            $journal, $label, $number, $date, $account, $accountLabel, $auxiliary, '' === $auxiliary ? '' : 'Scierie du Haut-Forez',
            $piece, $date, $text, $debit, $credit, $letter, '' === $letter ? '' : '20260125', $valid, '', '',
        ];

        return [
            $line('AN', 'À-nouveaux', 'AN-2026-00001', '20260101', '512000', 'Banques', '', 'AN-2026', 'Solde d’ouverture', '10000,00', '0,00', '20260115'),
            $line('AN', 'À-nouveaux', 'AN-2026-00001', '20260101', '101300', 'Capital souscrit - appelé, versé', '', 'AN-2026', 'Solde d’ouverture', '0,00', '10000,00', '20260115'),
            $line('ACH', 'Achats', 'ACH-2026-00001', '20260108', '607000', 'Achats de marchandises', '', 'FA-2601-0012', 'Facture Scierie', '3465,10', '0,00', '20260110'),
            $line('ACH', 'Achats', 'ACH-2026-00001', '20260108', '445660', 'TVA sur autres biens et services', '', 'FA-2601-0012', 'Facture Scierie', '693,02', '0,00', '20260110'),
            $line('ACH', 'Achats', 'ACH-2026-00001', '20260108', '401000', 'Fournisseurs', '401SCIE', 'FA-2601-0012', 'Facture Scierie', '0,00', '4158,12', '20260110', 'A'),
            $line('ACH', 'Achats', 'ACH-2026-00002', '20260112', '607000', 'Achats de marchandises', '', 'FA-2601-0020', 'Facture Scierie', '100,00', '0,00', '20260112'),
            $line('ACH', 'Achats', 'ACH-2026-00002', '20260112', '401000', 'Fournisseurs', '401SCIE', 'FA-2601-0020', 'Facture Scierie', '0,00', '100,00', '20260112'),
            $line('BQ', 'Banque', 'BQ-2026-00001', '20260120', '401000', 'Fournisseurs', '401SCIE', 'VIR-SCIE', 'Règlement Scierie', '4158,12', '0,00', '20260125', 'A'),
            $line('BQ', 'Banque', 'BQ-2026-00001', '20260120', '512000', 'Banques', '', 'VIR-SCIE', 'Règlement Scierie', '0,00', '4158,12', '20260125'),
        ];
    }

    /**
     * @param list<list<string>> $rows
     * @param list<string>       $header
     */
    private function text(array $rows, string $separator = "\t", array $header = FecFormat::COLUMNS): string
    {
        return implode("\r\n", [implode($separator, $header), ...array_map(static fn (array $row): string => implode($separator, $row), $rows)])."\r\n";
    }

    /**
     * @param list<list<string>> $rows
     * @param list<string>       $header
     */
    private function check(array $rows, string $name = self::NAME, array $header = FecFormat::COLUMNS): FecReport
    {
        return FecConformity::check($this->text($rows, "\t", $header), $name, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'));
    }

    /** @return list<string> */
    private function messages(FecReport $report): array
    {
        return array_map(static fn (FecFinding $finding): string => self::spaces($finding->message), $report->findings);
    }

    /** @return list<string> « line · zone · message » */
    private function located(FecReport $report): array
    {
        return array_map(static fn (FecFinding $finding): string => implode(' · ', array_filter([null === $finding->line ? null : (string) $finding->line, $finding->zone, self::spaces($finding->message)], static fn (?string $part): bool => null !== $part)), $report->findings);
    }

    /** Thousands are U+202F on the screen: plain spaces here. */
    private static function spaces(string $text): string
    {
        return str_replace(["\u{202F}", "\u{00A0}"], ' ', $text);
    }
}
