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

    /** A member's profile in a group's dossier given, changed or taken away by a teacher (step 1.7). */
    case MemberProfileChanged = 'dossier.member_profile_changed';

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

    /** An entry saved to the brouillard. */
    case EntryDrafted = 'accounting.entry_drafted';

    /** An entry of the brouillard changed. */
    case EntryChanged = 'accounting.entry_changed';

    /** An entry of the brouillard deleted. */
    case EntryDiscarded = 'accounting.entry_discarded';

    /** An entry validated: numbered, never changed again. */
    case EntryValidated = 'accounting.entry_validated';

    /** A validated entry reversed by its inverse entry. */
    case EntryReversed = 'accounting.entry_reversed';

    /** An entry template created. */
    case EntryTemplateCreated = 'accounting.entry_template_created';

    /** An entry template changed. */
    case EntryTemplateChanged = 'accounting.entry_template_changed';

    /** An entry template deleted. */
    case EntryTemplateDeleted = 'accounting.entry_template_deleted';

    /** A subscription created. */
    case EntrySubscriptionCreated = 'accounting.entry_subscription_created';

    /** A subscription changed. */
    case EntrySubscriptionChanged = 'accounting.entry_subscription_changed';

    /** A subscription deleted. */
    case EntrySubscriptionDeleted = 'accounting.entry_subscription_deleted';

    /** A subscription's occurrences generated up to the dossier's date. */
    case EntrySubscriptionGenerated = 'accounting.entry_subscription_generated';

    /** Lines of an account lettered together, by hand or automatically (step 1.6). */
    case LinesLettered = 'accounting.lines_lettered';

    /** A lettering undone (« délettrer »). */
    case LinesUnlettered = 'accounting.lines_unlettered';

    public function label(): string
    {
        return match ($this) {
            self::RegulatoryFileInstalled => 'Millésime réglementaire installé',
            self::RegulatoryValueAdded => 'Valeur réglementaire datée ajoutée',
            self::DossierCreated => 'Dossier créé',
            self::DossierCompanyChanged => 'Fiche société modifiée',
            self::DossierDateMoved => 'Date du dossier changée',
            self::MemberProfileChanged => 'Profil d’un membre changé',
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
            self::EntryDrafted => 'Écriture enregistrée au brouillard',
            self::EntryChanged => 'Écriture du brouillard modifiée',
            self::EntryDiscarded => 'Écriture du brouillard supprimée',
            self::EntryValidated => 'Écriture validée',
            self::EntryReversed => 'Écriture contrepassée',
            self::EntryTemplateCreated => 'Modèle d’écriture créé',
            self::EntryTemplateChanged => 'Modèle d’écriture modifié',
            self::EntryTemplateDeleted => 'Modèle d’écriture supprimé',
            self::EntrySubscriptionCreated => 'Abonnement créé',
            self::EntrySubscriptionChanged => 'Abonnement modifié',
            self::EntrySubscriptionDeleted => 'Abonnement supprimé',
            self::EntrySubscriptionGenerated => 'Écritures d’abonnement générées',
            self::LinesLettered => 'Lignes lettrées',
            self::LinesUnlettered => 'Lettrage défait',
        };
    }
}
