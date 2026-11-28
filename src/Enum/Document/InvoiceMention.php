<?php

declare(strict_types=1);

namespace App\Enum\Document;

/**
 * A mention a generated invoice prints and that the teacher may leave out on purpose (step 2.1b,
 * « mention manquante »): what a student checks on a pièce before entering it (Code de commerce
 * L441-9, CGI annexe II art. 242 nonies A). The supplier's name and address, the customer's, the
 * date, the lines and the totals are always printed.
 */
enum InvoiceMention: string
{
    case Number = 'number';
    case SupplierSiren = 'supplier_siren';
    case SupplierVatNumber = 'supplier_vat_number';
    case DueDate = 'due_date';
    case EarlyPayment = 'early_payment';
    case LatePenalties = 'late_penalties';
    case RecoveryFee = 'recovery_fee';

    public function label(): string
    {
        return match ($this) {
            self::Number => 'Numéro de facture',
            self::SupplierSiren => 'SIREN du fournisseur',
            self::SupplierVatNumber => 'Numéro de TVA du fournisseur',
            self::DueDate => 'Date d’échéance',
            self::EarlyPayment => 'Conditions d’escompte',
            self::LatePenalties => 'Pénalités de retard',
            self::RecoveryFee => 'Indemnité forfaitaire de recouvrement',
        };
    }
}
