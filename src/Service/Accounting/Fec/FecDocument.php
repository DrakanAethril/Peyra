<?php

declare(strict_types=1);

namespace App\Service\Accounting\Fec;

/**
 * A FEC as FecReader cut it: its separator and character set, its first line, and its lines of
 * zones (trimmed), each with its line number in the file. Nothing is checked here: FecConformity
 * says what is wrong with it.
 */
final readonly class FecDocument
{
    /**
     * @param list<string>             $header the zones of the first line
     * @param list<array{int, list<string>}> $lines  line number in the file, zones
     */
    public function __construct(
        public string $separator,
        public string $encoding,
        public array $header,
        public array $lines,
    ) {
    }

    /** Whether the first line names FecFormat::COLUMNS (false), SIGNED_COLUMNS (true), or neither (null). Case is not read. */
    public function signed(): ?bool
    {
        $header = array_map(mb_strtolower(...), $this->header);

        return match ($header) {
            array_map(mb_strtolower(...), FecFormat::COLUMNS) => false,
            array_map(mb_strtolower(...), FecFormat::SIGNED_COLUMNS) => true,
            default => null,
        };
    }

    /** « tabulation » or « | », for the report. */
    public function separatorLabel(): string
    {
        return "\t" === $this->separator ? 'tabulation' : '|';
    }
}
