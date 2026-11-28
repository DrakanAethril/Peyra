<?php

declare(strict_types=1);

namespace App\Enum\Mission;

/**
 * The modules of the student's PGI a mission opens (design-ux.md § 2.1, « le rail ne montre que les
 * modules ouverts par la mission »; step 2.9a): the rail's entries but the dashboard and the
 * Système d'information, always open. A screen belongs to the module its address names after
 * `/d/{dossier}/` (`ofPath()`): a closed module's screens answer a 404 to a student.
 */
enum MissionModule: string
{
    case Documents = 'documents';
    case Sales = 'sales';
    case Purchases = 'purchases';
    case Accounting = 'accounting';
    case Bank = 'bank';
    case Closing = 'closing';
    case Payroll = 'payroll';

    public function label(): string
    {
        return match ($this) {
            self::Documents => 'Pièces',
            self::Sales => 'Ventes',
            self::Purchases => 'Achats et stocks',
            self::Accounting => 'Comptabilité',
            self::Bank => 'Banque',
            self::Closing => 'Immobilisations et clôture',
            self::Payroll => 'Paie et social',
        };
    }

    /**
     * The module of an address of a dossier (« /d/12/sales/customers »), null for what belongs to
     * none (the dashboard, the Système d'information, the search, the date) or is not a dossier's.
     */
    public static function ofPath(string $path): ?self
    {
        if (1 !== preg_match('#^/d/\d+/([a-z-]+)(?:/([a-z-]+))?#', $path, $match)) {
            return null;
        }

        return match ($match[1]) {
            'documents' => self::Documents,
            'sales' => self::Sales,
            'purchases', 'inventory' => self::Purchases,
            'payments' => 'receipts' === ($match[2] ?? '') ? self::Sales : self::Purchases,
            'accounting' => self::Accounting,
            'bank' => self::Bank,
            'closing' => self::Closing,
            'payroll' => self::Payroll,
            default => null,
        };
    }
}
