<?php

declare(strict_types=1);

namespace App\Enum\Accounting;

/**
 * The amount a person types when an entry template is called (design-ux.md § 3.2, « Modèles
 * d'écriture »): the amount before VAT or the amount VAT included, from which the template's HT,
 * TVA and TTC lines are computed (TemplateComputation). A template with no such line asks none.
 */
enum TemplateBase: string
{
    case Net = 'ht';
    case Gross = 'ttc';

    public function label(): string
    {
        return match ($this) {
            self::Net => 'Montant HT',
            self::Gross => 'Montant TTC',
        };
    }

    /** In a sentence: « Indiquez le montant HT. » */
    public function phrase(): string
    {
        return match ($this) {
            self::Net => 'le montant HT',
            self::Gross => 'le montant TTC',
        };
    }
}
