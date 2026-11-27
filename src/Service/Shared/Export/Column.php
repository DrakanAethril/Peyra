<?php

declare(strict_types=1);

namespace App\Service\Shared\Export;

/** One column of an exported list: its header, what it holds, and its width in the spreadsheet (characters). */
final readonly class Column
{
    public function __construct(
        public string $title,
        public ColumnKind $kind = ColumnKind::Text,
        public ?int $width = null,
    ) {
    }
}
