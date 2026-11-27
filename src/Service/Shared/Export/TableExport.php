<?php

declare(strict_types=1);

namespace App\Service\Shared\Export;

use App\Service\Shared\Csv\CsvText;
use App\Service\Shared\Money;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * The export of a list to a spreadsheet (lot 1, step 1.5), in either format:
 *
 * - **CSV** through CsvText (UTF-8 with its mark, `;`, CRLF): an amount « 1234,56 » or « -138,00 »
 *   (no thousands separator, an ASCII minus: what a French spreadsheet reads as a number), a day
 *   « 12/11/2026 »;
 * - **XLSX** through openspout: an amount is a number with two decimals shown, a day a date cell,
 *   a code a text cell; the header row in bold, the sheet named after the list.
 *
 * Amounts are integer cents everywhere in Peyra. The spreadsheet's number is a binary float: the
 * cents become one here, at the file's boundary, and nowhere else.
 */
final class TableExport
{
    private const string AMOUNT_FORMAT = '#,##0.00';
    private const string DAY_FORMAT = 'dd/mm/yyyy';

    public static function csv(ExportTable $table): string
    {
        $rows = [array_map(static fn (Column $column): string => $column->title, $table->columns)];
        foreach ($table->rows() as $cells) {
            $row = [];
            foreach ($cells as $index => $cell) {
                $row[] = self::csvCell($table->columns[$index]->kind, $cell);
            }
            $rows[] = $row;
        }

        return CsvText::write($rows);
    }

    /** The XLSX file's bytes. */
    public static function xlsx(ExportTable $table): string
    {
        $path = tempnam(sys_get_temp_dir(), 'peyra-xlsx-');
        if (false === $path) {
            throw new \RuntimeException('No temporary file for the spreadsheet.');
        }
        try {
            $options = new Options();
            foreach ($table->columns as $index => $column) {
                $options->setColumnWidth(self::width($column), $index + 1);
            }
            $writer = new Writer($options);
            $writer->openToFile($path);
            $writer->getCurrentSheet()->setName(self::sheetName($table->name));

            $bold = new Style(fontBold: true);
            $writer->addRow(new Row(array_map(static fn (Column $column): Cell => Cell::fromValue($column->title, $bold), $table->columns)));
            $amount = new Style(format: self::AMOUNT_FORMAT);
            $day = new Style(format: self::DAY_FORMAT);
            foreach ($table->rows() as $cells) {
                $row = [];
                foreach ($cells as $index => $cell) {
                    $row[] = self::xlsxCell($table->columns[$index]->kind, $cell, $amount, $day);
                }
                $writer->addRow(new Row($row));
            }
            $writer->close();

            $bytes = file_get_contents($path);
            if (false === $bytes) {
                throw new \RuntimeException('The spreadsheet could not be read back.');
            }

            return $bytes;
        } finally {
            @unlink($path);
        }
    }

    /** The file as a download, named after the list and the dossier (« grand-livre-menuiserie-vasseur.xlsx »). */
    public static function response(ExportTable $table, ExportFormat $format, string $baseName): Response
    {
        $response = new Response(ExportFormat::Csv === $format ? self::csv($table) : self::xlsx($table));
        $response->headers->set('Content-Type', $format->contentType());
        $name = (new AsciiSlugger('fr'))->slug($baseName)->lower()->toString().'.'.$format->value;
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name));

        return $response;
    }

    /** « 1234,56 », « -138,00 », « 0,00 »: a number a French spreadsheet reads. */
    public static function csvAmount(Money $amount): string
    {
        $cents = abs($amount->cents);

        return ($amount->isNegative() ? '-' : '').intdiv($cents, 100).','.str_pad((string) ($cents % 100), 2, '0', \STR_PAD_LEFT);
    }

    private static function csvCell(ColumnKind $kind, string|Money|\DateTimeImmutable|null $cell): string
    {
        return match (true) {
            null === $cell => '',
            $cell instanceof Money => ColumnKind::AmountCell === $kind && $cell->isZero() ? '' : self::csvAmount($cell),
            $cell instanceof \DateTimeImmutable => $cell->format('d/m/Y'),
            default => $cell,
        };
    }

    private static function xlsxCell(ColumnKind $kind, string|Money|\DateTimeImmutable|null $cell, Style $amount, Style $day): Cell
    {
        return match (true) {
            null === $cell => Cell::fromValue(null),
            $cell instanceof Money => ColumnKind::AmountCell === $kind && $cell->isZero() ? Cell::fromValue(null) : new Cell\NumericCell($cell->cents / 100, $amount),
            $cell instanceof \DateTimeImmutable => new Cell\DateTimeCell($cell, $day),
            // A code stays text even when it is all digits (« 401000 », « 00012 »); so does any text.
            default => new Cell\StringCell($cell, null),
        };
    }

    private static function width(Column $column): float
    {
        return $column->width ?? match ($column->kind) {
            ColumnKind::Day => 12,
            ColumnKind::Amount, ColumnKind::AmountCell => 14,
            ColumnKind::Code => 12,
            ColumnKind::Text => 24,
        };
    }

    /** A sheet's name: 31 characters at most, none of \ / ? * [ ] : (Excel's rule). */
    private static function sheetName(string $name): string
    {
        $clean = trim((string) preg_replace('/[\\\\\/?*\[\]:]/u', ' ', $name));

        return mb_substr('' === $clean ? 'Export' : $clean, 0, 31);
    }
}
