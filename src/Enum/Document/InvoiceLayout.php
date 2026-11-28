<?php

declare(strict_types=1);

namespace App\Enum\Document;

/**
 * The three looks of a generated invoice (step 2.1b), so that a supplier's invoices do not all
 * look like another's: each is a template of `templates/document/invoice/`. A supplier always
 * prints with the same one (InvoiceCharter).
 */
enum InvoiceLayout: string
{
    /** Serif title, letterhead on the left, ruled table. */
    case Classic = 'classic';

    /** A band of the supplier's colour across the top, shaded table head. */
    case Banded = 'banded';

    /** Typewriter type, boxed blocks: a small business's invoicing software. */
    case Typed = 'typed';

    public function template(): string
    {
        return 'document/invoice/'.$this->value.'.pdf.html.twig';
    }
}
