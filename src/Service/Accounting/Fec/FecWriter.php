<?php

declare(strict_types=1);

namespace App\Service\Accounting\Fec;

/**
 * The FEC's text (A47 A-1): a first line naming the eighteen zones, then one line per entry line,
 * zones separated by a tab, lines by CRLF, in UTF-8 without a byte order mark (one of the three
 * character sets the article allows). A zone never carries the separators: a tab, a carriage
 * return or a line feed typed in a label becomes a space.
 */
final class FecWriter
{
    /** @param iterable<list<string>> $rows each of eighteen zones, in FecFormat::COLUMNS's order */
    public static function write(iterable $rows): string
    {
        $text = implode("\t", FecFormat::COLUMNS)."\r\n";
        foreach ($rows as $row) {
            if (\count($row) !== \count(FecFormat::COLUMNS)) {
                throw new \LogicException(\sprintf('A FEC line has %d zones, not %d.', \count($row), \count(FecFormat::COLUMNS)));
            }
            $text .= implode("\t", array_map(self::zone(...), $row))."\r\n";
        }

        return $text;
    }

    private static function zone(string $value): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));
    }
}
