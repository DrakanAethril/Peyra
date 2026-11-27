<?php

declare(strict_types=1);

namespace App\Service\Shared\Csv;

/**
 * The CSV files people exchange with a spreadsheet, both ways (plan of accounts, third parties…).
 *
 * Written: UTF-8 with a byte order mark (so a spreadsheet reads the accents), `;` between fields
 * (the French spreadsheet's own), CRLF between lines, a field quoted only when it must be.
 *
 * Read: the byte order mark dropped, Windows-1252 recognised when the bytes are not UTF-8 (a file
 * saved by an older spreadsheet), the separator guessed from the first line (`;`, tab or `,`),
 * blank lines skipped, each row kept with its line number for the report.
 */
final class CsvText
{
    /** @param list<list<string>> $rows the first one being the header */
    public static function write(array $rows): string
    {
        $out = "\u{FEFF}";
        foreach ($rows as $row) {
            $out .= implode(';', array_map(self::field(...), $row))."\r\n";
        }

        return $out;
    }

    /**
     * @return list<array{line: int, cells: list<string>}> the non-blank rows, cells trimmed
     *
     * @throws CsvRefused when the file is not text or has more lines than allowed
     */
    public static function read(string $bytes, int $maxRows): array
    {
        if (str_starts_with($bytes, "\u{FEFF}")) {
            $bytes = substr($bytes, 3);
        }
        if (str_contains($bytes, "\0")) {
            throw new CsvRefused('Ce fichier n’est pas un fichier texte : enregistrez-le au format CSV depuis le tableur.');
        }
        if (!mb_check_encoding($bytes, 'UTF-8')) {
            $bytes = (string) mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
        }
        $bytes = str_replace(["\r\n", "\r"], "\n", $bytes);
        $firstLine = strtok($bytes, "\n");
        $separator = self::separator(false === $firstLine ? '' : $firstLine);

        $handle = fopen('php://memory', 'r+');
        if (false === $handle) {
            throw new \RuntimeException('No memory stream.');
        }
        fwrite($handle, $bytes);
        rewind($handle);
        $rows = [];
        $line = 0;
        try {
            while (false !== ($cells = fgetcsv($handle, null, $separator, '"', ''))) {
                ++$line;
                $cells = array_map(static fn (?string $cell): string => trim((string) $cell), $cells);
                if ([''] === array_unique($cells)) {
                    continue;
                }
                if (\count($rows) >= $maxRows) {
                    throw new CsvRefused(\sprintf('Ce fichier dépasse %d lignes : découpez-le.', $maxRows));
                }
                $rows[] = ['line' => $line, 'cells' => $cells];
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    private static function separator(string $firstLine): string
    {
        $counts = [';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t"), ',' => substr_count($firstLine, ',')];
        arsort($counts);
        $best = array_key_first($counts);

        return $counts[$best] > 0 ? $best : ';';
    }

    private static function field(string $value): string
    {
        if (1 === preg_match('/[;"\r\n]/', $value) || $value !== trim($value)) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}
