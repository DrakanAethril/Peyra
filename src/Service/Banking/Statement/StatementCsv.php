<?php

declare(strict_types=1);

namespace App\Service\Banking\Statement;

use App\Service\Accounting\Chart\AccountSearch;
use App\Service\Shared\AmountParseError;
use App\Service\Shared\AmountParser;
use App\Service\Shared\Csv\CsvRefused;
use App\Service\Shared\Csv\CsvText;
use App\Service\Shared\DateValue;
use App\Service\Shared\Export\TableExport;
use App\Service\Shared\Money;

/**
 * A bank statement's CSV file read (design-ux.md § 3.2, « Import d'un relevé »; step 2.6a), a pure
 * function: one line per operation - a date (« 03/11/2026 » or « 2026-11-03 »), a wording, and its
 * amount either in a « débit » and a « crédit » column, as the bank prints them, or signed in one
 * « montant » column (positive: money in), as Pennylane's import and Peyra's export write it. A
 * header names the columns; without one, four columns read date, libellé, débit, crédit and three
 * read date, libellé, montant. Every faulty line is named; the caller writes nothing then.
 */
final class StatementCsv
{
    public const int MAX_LINES = 1000;

    /**
     * @return array{lines: list<array{date: \DateTimeImmutable, label: string, amount: Money}>, errors: array<string, string>}
     */
    public static function read(string $bytes): array
    {
        try {
            $rows = CsvText::read($bytes, self::MAX_LINES + 1);
        } catch (CsvRefused $refused) {
            return ['lines' => [], 'errors' => ['file' => $refused->getMessage()]];
        }
        if ([] === $rows) {
            return ['lines' => [], 'errors' => ['file' => 'Le fichier est vide : une ligne par opération est attendue.']];
        }
        $columns = self::header($rows[0]['cells']);
        if (null !== $columns) {
            array_shift($rows);
        } else {
            $columns = 3 === \count($rows[0]['cells']) ? ['date' => 0, 'label' => 1, 'amount' => 2] : ['date' => 0, 'label' => 1, 'debit' => 2, 'credit' => 3];
        }
        if (!isset($columns['date'], $columns['label']) || (!isset($columns['amount']) && !isset($columns['debit'], $columns['credit']))) {
            return ['lines' => [], 'errors' => ['file' => 'L’en-tête doit nommer une date, un libellé, et un montant ou un débit et un crédit.']];
        }

        $lines = [];
        $errors = [];
        foreach ($rows as $row) {
            $cells = $row['cells'];
            $cell = static fn (string $name): string => isset($columns[$name]) ? ($cells[$columns[$name]] ?? '') : '';
            $date = DateValue::parseFrench($cell('date')) ?? DateValue::parse($cell('date'));
            $label = trim(preg_replace('/\s+/u', ' ', $cell('label')) ?? '');
            $where = 'line.'.$row['line'];
            if (null === $date) {
                $errors[$where] = \sprintf('Ligne %d : « %s » n’est pas une date (JJ/MM/AAAA).', $row['line'], $cell('date'));
                continue;
            }
            if ('' === $label) {
                $errors[$where] = \sprintf('Ligne %d : le libellé manque.', $row['line']);
                continue;
            }
            try {
                if (isset($columns['amount'])) {
                    $amount = AmountParser::parse($cell('amount'));
                } else {
                    $debit = AmountParser::parseOptional($cell('debit'));
                    $credit = AmountParser::parseOptional($cell('credit'));
                    if (null !== $debit && null !== $credit && !$debit->isZero() && !$credit->isZero()) {
                        $errors[$where] = \sprintf('Ligne %d : un débit et un crédit à la fois.', $row['line']);
                        continue;
                    }
                    $amount = ($credit ?? Money::zero())->abs()->minus(($debit ?? Money::zero())->abs());
                }
            } catch (AmountParseError $error) {
                $errors[$where] = \sprintf('Ligne %d : %s', $row['line'], $error->getMessage());
                continue;
            }
            if ($amount->isZero()) {
                $errors[$where] = \sprintf('Ligne %d : le montant est nul.', $row['line']);
                continue;
            }
            $lines[] = ['date' => $date, 'label' => mb_substr($label, 0, 160), 'amount' => $amount];
        }
        if (\count($lines) + \count($errors) > self::MAX_LINES) {
            return ['lines' => [], 'errors' => ['file' => \sprintf('Un relevé tient en %d lignes au plus.', self::MAX_LINES)]];
        }
        if ([] === $lines && [] === $errors) {
            $errors['file'] = 'Le fichier ne porte aucune opération.';
        }

        return ['lines' => $lines, 'errors' => $errors];
    }

    /**
     * The columns a header names, or null when the first line is not a header.
     *
     * @param list<string> $cells
     *
     * @return array<string, int>|null
     */
    private static function header(array $cells): ?array
    {
        $columns = [];
        foreach ($cells as $index => $cell) {
            $name = match (AccountSearch::fold($cell)) {
                'date', 'date operation', 'date d\'operation', 'date de l\'operation' => 'date',
                'libelle', 'label', 'intitule', 'libelle operation' => 'label',
                'debit' => 'debit',
                'credit' => 'credit',
                'montant', 'amount' => 'amount',
                default => null,
            };
            if (null !== $name && !isset($columns[$name])) {
                $columns[$name] = $index;
            }
        }

        return isset($columns['date']) ? $columns : null;
    }

    /**
     * The statement written back (« Exporter le relevé », the Pennylane session): date, wording,
     * signed amount.
     *
     * @param list<array{date: \DateTimeImmutable, label: string, amount: Money}> $lines
     */
    public static function write(array $lines): string
    {
        $rows = [['Date', 'Libellé', 'Montant']];
        foreach ($lines as $line) {
            $rows[] = [$line['date']->format('d/m/Y'), $line['label'], TableExport::csvAmount($line['amount'])];
        }

        return CsvText::write($rows);
    }
}
