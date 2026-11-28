<?php

declare(strict_types=1);

namespace App\Enum\Sales;

use App\Enum\ThirdParty\ThirdPartyKind;

/**
 * The commercial documents of the sales chain (lot 2, step 2.3): a quote becomes an order, an
 * order a delivery note, a delivery note an invoice - each one transformed into a later one -, and
 * a validated invoice is corrected by a credit note, never changed. An order may first be invoiced
 * in part by deposit invoices (step 2.3b), which its final invoice deducts. One entity carries them
 * all (SalesDocument); each type numbers on its own (« FV-2026-0001 »).
 *
 * Step 2.4 adds the purchasing side on the same document: a price request becomes a supplier order,
 * an order one or several receipts (« bon de réception »), each receiving what is left of it. The
 * supplier's invoice is not one of them: it is a pièce of the inbox, matched to its order and
 * receipts (App\Service\Purchasing). `side()` says which module a type belongs to.
 */
enum SalesDocumentType: string
{
    case Quote = 'quote';
    case Order = 'order';
    case Delivery = 'delivery';
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';
    case Deposit = 'deposit';
    case PriceRequest = 'price_request';
    case PurchaseOrder = 'purchase_order';
    case Receipt = 'receipt';

    public function label(): string
    {
        return match ($this) {
            self::Quote => 'Devis',
            self::Order => 'Commande',
            self::Delivery => 'Bon de livraison',
            self::Invoice => 'Facture',
            self::CreditNote => 'Avoir',
            self::Deposit => 'Facture d’acompte',
            self::PriceRequest => 'Demande de prix',
            self::PurchaseOrder => 'Commande fournisseur',
            self::Receipt => 'Bon de réception',
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
            self::Deposit => 'Acomptes',
            self::PriceRequest => 'Demandes de prix',
            self::PurchaseOrder => 'Commandes',
            self::Receipt => 'Réceptions',
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
            self::Deposit => 'une facture d’acompte',
            self::PriceRequest => 'une demande de prix',
            self::PurchaseOrder => 'une commande fournisseur',
            self::Receipt => 'un bon de réception',
        };
    }

    /** The number's prefix: DE-2026-0001, BC-, BL-, FV-, AV-, AC-; DP-, CF-, BR- for the purchases. */
    public function prefix(): string
    {
        return match ($this) {
            self::Quote => 'DE',
            self::Order => 'BC',
            self::Delivery => 'BL',
            self::Invoice => 'FV',
            self::CreditNote => 'AV',
            self::Deposit => 'AC',
            self::PriceRequest => 'DP',
            self::PurchaseOrder => 'CF',
            self::Receipt => 'BR',
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
            self::Deposit => 'FACTURE D’ACOMPTE',
            self::PriceRequest => 'DEMANDE DE PRIX',
            self::PurchaseOrder => 'BON DE COMMANDE',
            self::Receipt => 'BON DE RÉCEPTION',
        };
    }

    /**
     * What a validated document of this type may be turned into: any later step of the chain for
     * a quote, an order or a delivery note - and a deposit invoice for an order -, a credit note for
     * an invoice, nothing for a credit note or a deposit invoice. On the purchasing side, a price
     * request becomes an order and an order its receipts.
     *
     * @return list<self>
     */
    public function transformsInto(): array
    {
        return match ($this) {
            self::Quote => [self::Order, self::Delivery, self::Invoice],
            self::Order => [self::Delivery, self::Invoice, self::Deposit],
            self::Delivery => [self::Invoice],
            self::Invoice => [self::CreditNote],
            self::PriceRequest => [self::PurchaseOrder],
            self::PurchaseOrder => [self::Receipt],
            self::CreditNote, self::Deposit, self::Receipt => [],
        };
    }

    /** An invoicing document - an invoice, a credit note, a deposit invoice: it goes to the books (step 2.3b). */
    public function isInvoicing(): bool
    {
        return self::Invoice === $this || self::CreditNote === $this || self::Deposit === $this;
    }

    /** It may carry a cash discount (« escompte »): an invoice, and the credit note that reverses it. */
    public function takesCashDiscount(): bool
    {
        return self::Invoice === $this || self::CreditNote === $this;
    }

    /** A delivery note, a receipt and a price request print quantities, never prices. */
    public function printsPrices(): bool
    {
        return self::Delivery !== $this && self::Receipt !== $this && self::PriceRequest !== $this;
    }

    /** It goes to the third party (simulated): every document but a receipt, which the company keeps. */
    public function isSent(): bool
    {
        return self::Receipt !== $this;
    }

    /** A document of the purchasing side (Achats et stocks, step 2.4). */
    public function isPurchase(): bool
    {
        return self::PriceRequest === $this || self::PurchaseOrder === $this || self::Receipt === $this;
    }

    /** The module, as its addresses say it: `sales` or `purchases`. */
    public function side(): string
    {
        return $this->isPurchase() ? 'purchases' : 'sales';
    }

    /** The third party it is made for: a customer, or a supplier on the purchasing side. */
    public function partyKind(): ThirdPartyKind
    {
        return $this->isPurchase() ? ThirdPartyKind::Supplier : ThirdPartyKind::Customer;
    }

    /** « Client » or « Fournisseur ». */
    public function partyLabel(): string
    {
        return $this->isPurchase() ? 'Fournisseur' : 'Client';
    }

    /** A price may be left empty (zero): a price request asks the supplier for it. */
    public function asksPrices(): bool
    {
        return self::PriceRequest === $this;
    }

    /** @return list<self> the types of a list's tab: the chain before the invoice, the invoicing, or the purchases */
    public function family(): array
    {
        if ($this->isPurchase()) {
            return [self::PriceRequest, self::PurchaseOrder, self::Receipt];
        }

        return $this->isInvoicing() ? [self::Invoice, self::CreditNote, self::Deposit] : [self::Quote, self::Order, self::Delivery];
    }

    /** @return list<self> the steps of its chain, in order, drawn at the head of a fiche */
    public function chainOrder(): array
    {
        return $this->isPurchase() ? [self::PriceRequest, self::PurchaseOrder, self::Receipt] : [self::Quote, self::Order, self::Delivery, self::Invoice];
    }
}
