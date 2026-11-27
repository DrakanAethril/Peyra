<?php

declare(strict_types=1);

namespace App\Enum\Audit;

/**
 * What a line of the audit trail records. One case per gesture, named by its domain; the label is
 * what the trail's reader shows. The dossier's gestures join it with their steps.
 */
enum AuditAction: string
{
    /** A millésime file installed by app:regulatory:sync: values added, superseded, kept. */
    case RegulatoryFileInstalled = 'regulatory.file_installed';

    /** A dated value entered by an administrator under Paramètres réglementaires. */
    case RegulatoryValueAdded = 'regulatory.value_added';

    /** A dossier made by a command (app:demo:install, app:dev:dossiers); the library's gestures join at step 1.8. */
    case DossierCreated = 'dossier.created';

    /** The fiche société saved. */
    case DossierCompanyChanged = 'dossier.company_changed';

    /** The dossier's date moved by a teacher. */
    case DossierDateMoved = 'dossier.date_moved';

    public function label(): string
    {
        return match ($this) {
            self::RegulatoryFileInstalled => 'Millésime réglementaire installé',
            self::RegulatoryValueAdded => 'Valeur réglementaire datée ajoutée',
            self::DossierCreated => 'Dossier créé',
            self::DossierCompanyChanged => 'Fiche société modifiée',
            self::DossierDateMoved => 'Date du dossier changée',
        };
    }
}
