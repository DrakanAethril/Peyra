<?php

declare(strict_types=1);

namespace App\Service\Shared\Export;

/**
 * What a column of an exported list holds, which decides how each format writes it: a text, a
 * code (kept as text, never read as a number by the spreadsheet: « 401000 », « 00012 »), an amount
 * (signed, zero written), a debit or credit cell (empty for zero, as on screen), a day.
 */
enum ColumnKind
{
    case Text;
    case Code;
    case Amount;
    case AmountCell;
    case Day;
}
