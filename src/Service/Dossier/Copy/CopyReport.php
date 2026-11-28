<?php

declare(strict_types=1);

namespace App\Service\Dossier\Copy;

/** What a copy wrote: the rows of each table and the files. */
final readonly class CopyReport
{
    /** @param array<string, int> $rows by table */
    public function __construct(public array $rows, public int $files)
    {
    }

    public function rowCount(): int
    {
        return array_sum($this->rows);
    }

    /** The rows of one table (« entry »: the entries copied). */
    public function rowsOf(string $table): int
    {
        return $this->rows[$table] ?? 0;
    }
}
