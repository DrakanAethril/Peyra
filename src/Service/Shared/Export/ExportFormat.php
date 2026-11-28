<?php

declare(strict_types=1);

namespace App\Service\Shared\Export;

/** The spreadsheet formats a list is exported in (lot 1, step 1.5): CSV and XLSX. */
enum ExportFormat: string
{
    case Csv = 'csv';
    case Xlsx = 'xlsx';

    public function contentType(): string
    {
        return match ($this) {
            self::Csv => 'text/csv; charset=UTF-8',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };
    }
}
