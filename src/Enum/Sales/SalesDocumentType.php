<?php

declare(strict_types=1);

namespace App\Enum\Sales;

/**
 * The commercial documents of the sales chain (lot 2, step 2.3): a quote becomes an order, an
 * order a delivery note, a delivery note an invoice - each one transformed into a later one -, and
 * a validated invoice is corrected by a credit note, never changed. One entity carries them all
 * (SalesDocument); each type numbers on its own (« FV-2026-0001 »).
 */
enum SalesDocumentType: string
{
    case Quote = 'quote';
    case Order = 'order';
    case Delivery = 'delivery';
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';

    public function label(): string
    {
        return match ($this) {
            self::Quote => 'Devis',
            self::Order => 'Commande',
            self::Delivery => 'Bon de livraison',
            self::Invoice => 'Facture',
            self::CreditNote => 'Avoir',
        };
    }

    /** The list's filter chip. */
    public function plural(): string
    {
        return match ($this) {
            self::Quote => 'Devis',
            self::Order => 'Commandes',
            self::Delivery => 'Livraisons',
            self::Invoice => 'Factures',
            self::CreditNote => 'Avoirs',
        };
    }

    /** « un devis », « une facture »: in a sentence. */
    public function withArticle(): string
    {
        return match ($this) {
            self::Quote => 'un devis',
            self::Order => 'une commande',
            self::Delivery => 'un bon de livraison',
            self::Invoice => 'une facture',
            self::CreditNote => 'un avoir',
        };
    }

    /** The number's prefix: DE-2026-0001, BC-, BL-, FV-, AV-. */
    public function prefix(): string
    {
        return match ($this) {
            self::Quote => 'DE',
            self::Order => 'BC',
            self::Delivery => 'BL',
            self::Invoice => 'FV',
            self::CreditNote => 'AV',
        };
    }

    /** The title printed on the paper. */
    public function title(): string
    {
        return match ($this) {
            self::Quote => 'DEVIS',
            self::Order => 'CONFIRMATION DE COMMANDE',
            self::Delivery => 'BON DE LIVRAISON',
            self::Invoice => 'FACTURE',
            self::CreditNote => 'AVOIR',
        };
    }

    /**
     * What a validated document of this type may be turned into: any later step of the chain for
     * a quote, an order or a delivery note, a credit note for an invoice, nothing for a credit note.
     *
     * @return list<self>
     */
    public function transformsInto(): array
    {
        return match ($this) {
            self::Quote => [self::Order, self::Delivery, self::Invoice],
            self::Order => [self::Delivery, self::Invoice],
            self::Delivery => [self::Invoice],
            self::Invoice => [self::CreditNote],
            self::CreditNote => [],
        };
    }

    /** The chain drawn at the head of a fiche: the steps before the invoice, then the invoice. */
    public function isInvoicing(): bool
    {
        return self::Invoice === $this || self::CreditNote === $this;
    }

    /** A delivery note prints quantities, never prices. */
    public function printsPrices(): bool
    {
        return self::Delivery !== $this;
    }

    /** @return list<self> the types of a list's tab: the chain before the invoice, or the invoicing */
    public function family(): array
    {
        return $this->isInvoicing() ? [self::Invoice, self::CreditNote] : [self::Quote, self::Order, self::Delivery];
    }
}
