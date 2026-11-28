<?php

declare(strict_types=1);

namespace App\Enum\Document;

/**
 * A fault the generator puts into an invoice on request (step 2.1b, « pièces fautives »), besides
 * a missing mention (InvoiceMention): it is in the document itself, so its read data carry it too.
 * Never shown to a student: finding it is the exercise.
 */
enum InvoiceFault: string
{
    /** The supplier miscalculated the VAT of a rate: the totals follow the wrong VAT. */
    case VatMiscalculated = 'vat_miscalculated';

    /** The total including tax printed is not the sum of the others. */
    case WrongTotal = 'wrong_total';

    /** The invoice is addressed to another company. */
    case WrongRecipient = 'wrong_recipient';

    /** The pièce arrives twice in the inbox. */
    case Duplicate = 'duplicate';

    public function label(): string
    {
        return match ($this) {
            self::VatMiscalculated => 'TVA mal calculée',
            self::WrongTotal => 'Total faux',
            self::WrongRecipient => 'Mauvais destinataire',
            self::Duplicate => 'Doublon : la pièce arrive deux fois',
        };
    }
}
