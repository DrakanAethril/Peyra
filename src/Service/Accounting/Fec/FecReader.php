<?php

declare(strict_types=1);

namespace App\Service\Accounting\Fec;

/**
 * A FEC file cut into lines and zones (A47 A-1): UTF-8, its byte order mark dropped, or else
 * ISO 8859-15 (the article's other character set; plain ASCII reads as either); lines ended by CRLF,
 * LF or CR, blank lines skipped; zones separated by the tab or the « | » the first line uses.
 * Answers the document, or the sentence that says why the bytes are not a FEC at all.
 */
final class FecReader
{
    public static function read(string $bytes): FecDocument|string
    {
        if (str_starts_with($bytes, "\u{FEFF}")) {
            $bytes = substr($bytes, 3);
        }
        $encoding = 'UTF-8';
        if (!mb_check_encoding($bytes, 'UTF-8')) {
            $encoding = 'ISO-8859-15';
            $bytes = (string) mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-15');
        }

        $rows = preg_split('/\r\n|\n|\r/', $bytes) ?: [];
        $header = null;
        $separator = "\t";
        $lines = [];
        foreach ($rows as $index => $row) {
            if ('' === trim($row)) {
                continue;
            }
            if (null === $header) {
                if (str_contains($row, "\t")) {
                    $separator = "\t";
                } elseif (str_contains($row, '|')) {
                    $separator = '|';
                } else {
                    return 'La première ligne doit nommer les zones du fichier, séparées par une tabulation ou par le caractère « | » : ce fichier n’est pas un FEC.';
                }
                $header = array_map(trim(...), explode($separator, $row));
                continue;
            }
            $lines[] = [$index + 1, array_map(trim(...), explode($separator, $row))];
        }
        if (null === $header) {
            return 'Le fichier est vide.';
        }

        return new FecDocument($separator, $encoding, $header, $lines);
    }
}
