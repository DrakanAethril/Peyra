<?php

declare(strict_types=1);

namespace App\Enum\Document;

/**
 * The nature of a pièce (lot 2, step 2.1): what a dossier receives or issues. It says what the
 * pièce is, never how it is entered - a purchase invoice may end in the purchases' journal or in
 * the bank's (a ticket paid by card).
 */
enum DocumentType: string
{
    case PurchaseInvoice = 'purchase_invoice';
    case SalesInvoice = 'sales_invoice';
    case CreditNote = 'credit_note';
    case BankStatement = 'bank_statement';
    case Receipt = 'receipt';
    case ExpenseReport = 'expense_report';
    case Payslip = 'payslip';
    case TaxNotice = 'tax_notice';
    case Contract = 'contract';
    case Letter = 'letter';

    public function label(): string
    {
        return match ($this) {
            self::PurchaseInvoice => 'Facture d’achat',
            self::SalesInvoice => 'Facture de vente',
            self::CreditNote => 'Avoir',
            self::BankStatement => 'Relevé bancaire',
            self::Receipt => 'Ticket',
            self::ExpenseReport => 'Note de frais',
            self::Payslip => 'Bulletin de paie',
            self::TaxNotice => 'Avis d’imposition',
            self::Contract => 'Contrat',
            self::Letter => 'Courrier',
        };
    }
}
