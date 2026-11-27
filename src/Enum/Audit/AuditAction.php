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

    /** A reference chart copied into a dossier's empty plan. */
    case ChartInstalled = 'accounting.chart_installed';

    /** An account added to a dossier's plan by hand. */
    case AccountCreated = 'accounting.account_created';

    /** An account of the plan changed (number, label, flags). */
    case AccountChanged = 'accounting.account_changed';

    /** An unused account taken out of the plan. */
    case AccountDeleted = 'accounting.account_deleted';

    /** A plan of accounts imported from a CSV file. */
    case ChartImported = 'accounting.chart_imported';

    /** A journal created. */
    case JournalCreated = 'accounting.journal_created';

    /** A journal changed (code, label, type, counterpart). */
    case JournalChanged = 'accounting.journal_changed';

    /** A journal deleted. */
    case JournalDeleted = 'accounting.journal_deleted';

    /** A third party created. */
    case ThirdPartyCreated = 'third_party.created';

    /** A third party changed (any field of its fiche). */
    case ThirdPartyChanged = 'third_party.changed';

    /** A third party deleted. */
    case ThirdPartyDeleted = 'third_party.deleted';

    /** Third parties imported from a CSV file. */
    case ThirdPartiesImported = 'third_party.imported';

    public function label(): string
    {
        return match ($this) {
            self::RegulatoryFileInstalled => 'Millésime réglementaire installé',
            self::RegulatoryValueAdded => 'Valeur réglementaire datée ajoutée',
            self::DossierCreated => 'Dossier créé',
            self::DossierCompanyChanged => 'Fiche société modifiée',
            self::DossierDateMoved => 'Date du dossier changée',
            self::ChartInstalled => 'Plan de comptes installé',
            self::AccountCreated => 'Compte créé',
            self::AccountChanged => 'Compte modifié',
            self::AccountDeleted => 'Compte supprimé',
            self::ChartImported => 'Plan de comptes importé',
            self::JournalCreated => 'Journal créé',
            self::JournalChanged => 'Journal modifié',
            self::JournalDeleted => 'Journal supprimé',
            self::ThirdPartyCreated => 'Tiers créé',
            self::ThirdPartyChanged => 'Tiers modifié',
            self::ThirdPartyDeleted => 'Tiers supprimé',
            self::ThirdPartiesImported => 'Tiers importés',
        };
    }
}
