<?php

declare(strict_types=1);

namespace App\Enum\Accounting;

/** How the automatic lettering pairs lines (lot 1, step 1.6; App\Service\Accounting\Lettering\LetteringProposals). */
enum LetteringMode: string
{
    case Amount = 'amount';
    case Document = 'document';

    public function label(): string
    {
        return match ($this) {
            self::Amount => 'Par montant',
            self::Document => 'Par pièce',
        };
    }

    /** « par montant », inside a sentence. */
    public function phrase(): string
    {
        return mb_strtolower($this->label());
    }
}
