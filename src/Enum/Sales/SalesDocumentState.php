<?php

declare(strict_types=1);

namespace App\Enum\Sales;

use App\Enum\Shared\BadgeTone;
use App\Enum\Shared\HasBadge;

/**
 * Where a commercial document stands (step 2.3): a draft is changed or deleted at will and carries
 * no number; validated, it takes the next number of its type and never changes again - an invoice
 * is corrected by a credit note.
 */
enum SalesDocumentState: string implements HasBadge
{
    case Draft = 'draft';
    case Validated = 'validated';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Validated => 'Validé',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Draft => BadgeTone::Warning,
            self::Validated => BadgeTone::Success,
        };
    }
}
