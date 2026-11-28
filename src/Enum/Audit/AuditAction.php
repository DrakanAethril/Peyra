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

    /** A template's card changed: title, description, level, processes (step 1.8a). */
    case TemplateCardChanged = 'library.card_changed';

    /** A template shared with a colleague, its right changed, or the share taken back. */
    case TemplateShared = 'library.shared';

    case TemplateUnshared = 'library.unshared';

    /** A template deleted from the library, with its content (its trail stays). */
    case TemplateDeleted = 'library.deleted';

    /** A restore point asked for, by a person or before a return (step 1.8b). */
    case RestorePointPosed = 'restore.point_posed';

    /** The copy of a restore point stopped: the dossier is untouched. */
    case RestorePointFailed = 'restore.point_failed';

    /** A restore point deleted by a person. */
    case RestorePointDeleted = 'restore.point_deleted';

    /** The dossier taken back to one of its restore points. */
    case DossierRestored = 'restore.dossier_restored';

    /** A return to a restore point that stopped: the dossier stayed as it was. */
    case DossierRestorationFailed = 'restore.dossier_restoration_failed';

    /** A template handed out to students of a class (step 1.8c); each dossier made is a DossierCreated of its own. */
    case DistributionLaunched = 'distribution.launched';

    /** A dossier of a distribution whose copy stopped: nothing of it was kept. */
    case DistributionCopyFailed = 'distribution.copy_failed';

    /** A failed dossier of a distribution put back in the queue. */
    case DistributionCopyRetried = 'distribution.copy_retried';

    /** A distribution withdrawn by a teacher: its dossiers deleted (their trails stay). */
    case DistributionWithdrawn = 'distribution.withdrawn';

    /** The establishment of the payroll set (headcount, work accident and mobility rates, working time; step 4.1). */
    case PayrollEstablishmentChanged = 'payroll.establishment_changed';

    /** A collecting body created, changed or deleted. */
    case PayrollBodyCreated = 'payroll.body_created';

    case PayrollBodyChanged = 'payroll.body_changed';

    case PayrollBodyDeleted = 'payroll.body_deleted';

    /** The standard plan de paie installed in an empty plan. */
    case PayrollPlanInstalled = 'payroll.plan_installed';

    /** An item of the plan de paie created, changed or deleted. */
    case PayrollItemCreated = 'payroll.item_created';

    case PayrollItemChanged = 'payroll.item_changed';

    case PayrollItemDeleted = 'payroll.item_deleted';

    /** An employee created, changed (their contract's terms from a day on included) or deleted (step 4.2a). */
    case EmployeeCreated = 'payroll.employee_created';

    case EmployeeChanged = 'payroll.employee_changed';

    case EmployeeDeleted = 'payroll.employee_deleted';

    /** A DPAE deposited on the simulated social portal and accepted (step 4.2b). */
    case HiringDeclared = 'payroll.hiring_declared';

    /** The information and prevention visit's day or an affiliation recorded (step 4.2b). */
    case HiringFormalitiesChanged = 'payroll.hiring_formalities_changed';

    /** An employee's exit recorded or changed, or cancelled (step 4.2c). */
    case EmployeeExitRecorded = 'payroll.employee_exit_recorded';

    case EmployeeExitCancelled = 'payroll.employee_exit_cancelled';

    /** A payslip validated (frozen), its validation cancelled, or sent to the employee - simulated (step 4.4b). */
    case PayslipValidated = 'payroll.payslip_validated';

    case PayslipCancelled = 'payroll.payslip_cancelled';

    case PayslipSent = 'payroll.payslip_sent';

    /** A month of pay closed, or reopened by a teacher (step 4.4b). */
    case PayrollMonthClosed = 'payroll.month_closed';

    case PayrollMonthReopened = 'payroll.month_reopened';

    /** A pièce deposited into the dossier, or its read data changed (step 2.1a). */
    case DocumentDeposited = 'document.deposited';

    case DocumentReadingChanged = 'document.reading_changed';

    /** A pièce made by the teacher's generator (step 2.1b). */
    case DocumentGenerated = 'document.generated';

    /** A pièce's entry proposed again from its read data, an anomaly reported on it, a pièce rejected (step 2.2a). */
    case DocumentProposalRebuilt = 'document.proposal_rebuilt';

    case DocumentAnomalyReported = 'document.anomaly_reported';

    case DocumentRejected = 'document.rejected';

    /** A pièce's entry written in the brouillard: the pièce is posted (step 2.2a). */
    case DocumentPosted = 'document.posted';

    /** An error slipped into a pièce of a template or taken back, its right proposal fixed, a doublon added or withdrawn (step 2.2b). */
    case DocumentErrorSlipped = 'document.error_slipped';

    case DocumentErrorRemoved = 'document.error_removed';

    case DocumentProposalFixed = 'document.proposal_fixed';

    case DocumentDuplicateAdded = 'document.duplicate_added';

    case DocumentDuplicateWithdrawn = 'document.duplicate_withdrawn';

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
            self::TemplateCardChanged => 'Fiche du modèle modifiée',
            self::TemplateShared => 'Modèle partagé',
            self::TemplateUnshared => 'Partage du modèle retiré',
            self::TemplateDeleted => 'Modèle supprimé',
            self::RestorePointPosed => 'Point de restauration posé',
            self::RestorePointFailed => 'Point de restauration en échec',
            self::RestorePointDeleted => 'Point de restauration supprimé',
            self::DossierRestored => 'Retour à un point de restauration',
            self::DossierRestorationFailed => 'Retour à un point en échec',
            self::DistributionLaunched => 'Modèle distribué',
            self::DistributionCopyFailed => 'Copie d’une distribution en échec',
            self::DistributionCopyRetried => 'Copie d’une distribution relancée',
            self::DistributionWithdrawn => 'Distribution retirée',
            self::PayrollEstablishmentChanged => 'Établissement de la paie modifié',
            self::PayrollBodyCreated => 'Organisme social créé',
            self::PayrollBodyChanged => 'Organisme social modifié',
            self::PayrollBodyDeleted => 'Organisme social supprimé',
            self::PayrollPlanInstalled => 'Plan de paie installé',
            self::PayrollItemCreated => 'Rubrique de paie créée',
            self::PayrollItemChanged => 'Rubrique de paie modifiée',
            self::PayrollItemDeleted => 'Rubrique de paie supprimée',
            self::EmployeeCreated => 'Salarié créé',
            self::EmployeeChanged => 'Salarié modifié',
            self::HiringDeclared => 'DPAE déposée',
            self::HiringFormalitiesChanged => 'Formalités d’embauche modifiées',
            self::EmployeeDeleted => 'Salarié supprimé',
            self::EmployeeExitRecorded => 'Sortie de salarié enregistrée',
            self::EmployeeExitCancelled => 'Sortie de salarié annulée',
            self::PayslipValidated => 'Bulletin de paie validé',
            self::PayslipCancelled => 'Validation d’un bulletin de paie annulée',
            self::PayslipSent => 'Bulletin de paie envoyé au salarié',
            self::PayrollMonthClosed => 'Mois de paie clos',
            self::PayrollMonthReopened => 'Mois de paie rouvert',
            self::DocumentDeposited => 'Pièce déposée',
            self::DocumentReadingChanged => 'Données lues d’une pièce modifiées',
            self::DocumentGenerated => 'Pièce générée',
            self::DocumentProposalRebuilt => 'Écriture d’une pièce reprise depuis ses données lues',
            self::DocumentAnomalyReported => 'Anomalie signalée sur une pièce',
            self::DocumentRejected => 'Pièce rejetée',
            self::DocumentPosted => 'Pièce comptabilisée',
            self::DocumentErrorSlipped => 'Erreur glissée dans une pièce',
            self::DocumentErrorRemoved => 'Erreur retirée d’une pièce',
            self::DocumentProposalFixed => 'Écriture juste d’une pièce corrigée',
            self::DocumentDuplicateAdded => 'Doublon glissé',
            self::DocumentDuplicateWithdrawn => 'Doublon retiré',
        };
    }
}
