<?php

declare(strict_types=1);

namespace App\Service\Shared\Export;

use App\Service\Shared\Money;

/**
 * A list as it leaves Peyra for a spreadsheet: a name (the sheet's, and the file's), its columns,
 * its rows - every row of the filter, never only the page shown. Cells are typed values: a string,
 * a Money, a day, or null for an empty cell; the column says how each format writes them
 * (TableExport).
 */
final class ExportTable
{
    /** @var list<list<string|Money|\DateTimeImmutable|null>> */
    private array $rows = [];

    /** @param list<Column> $columns */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
    ) {
        if ([] === $columns) {
            throw new \InvalidArgumentException('An exported list has columns.');
        }
    }

    /** @param list<string|Money|\DateTimeImmutable|null> $cells one per column, in their order */
    public function add(array $cells): void
    {
        if (\count($cells) !== \count($this->columns)) {
            throw new \InvalidArgumentException(\sprintf('A row of « %s » has %d cells for %d columns.', $this->name, \count($cells), \count($this->columns)));
        }
        $this->rows[] = $cells;
    }

    /** @return list<list<string|Money|\DateTimeImmutable|null>> */
    public function rows(): array
    {
        return $this->rows;
    }
}
