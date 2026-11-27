<?php

declare(strict_types=1);

namespace App\Enum\Audit;

/**
 * What a line of the audit trail records. One case per gesture, named by its domain; the label is
 * what the trail's reader shows. The dossier's gestures (lot 1) join the list.
 */
enum AuditAction: string
{
    /** A millésime file installed by app:regulatory:sync: values added, superseded, kept. */
    case RegulatoryFileInstalled = 'regulatory.file_installed';

    /** A dated value entered by an administrator under Paramètres réglementaires. */
    case RegulatoryValueAdded = 'regulatory.value_added';

    public function label(): string
    {
        return match ($this) {
            self::RegulatoryFileInstalled => 'Millésime réglementaire installé',
            self::RegulatoryValueAdded => 'Valeur réglementaire datée ajoutée',
        };
    }
}
