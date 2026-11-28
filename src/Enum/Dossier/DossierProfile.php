<?php

declare(strict_types=1);

namespace App\Enum\Dossier;

/**
 * A member's profile in a group's dossier (conception § 4, lot 1 step 1.7): the job they hold in the
 * service the group plays, which opens or closes the journals and the gestures of the dossier
 * (App\Service\Dossier\Rights\ProfileMatrix). A member without a profile, and anybody in a
 * dossier that is not a group's, has every right of the dossier.
 */
enum DossierProfile: string
{
    case SupplierAccountant = 'supplier_accountant';
    case CustomerAccountant = 'customer_accountant';
    case PayrollManager = 'payroll_manager';
    case ChiefAccountant = 'chief_accountant';

    public function label(): string
    {
        return match ($this) {
            self::SupplierAccountant => 'Comptable fournisseurs',
            self::CustomerAccountant => 'Comptable clients',
            self::PayrollManager => 'Gestionnaire de paie',
            self::ChiefAccountant => 'Chef comptable',
        };
    }

    /** What the job is, in one sentence (Utilisateurs et droits). */
    public function description(): string
    {
        return match ($this) {
            self::SupplierAccountant => 'Saisit et valide les achats, saisit les règlements fournisseurs, lettre les fournisseurs.',
            self::CustomerAccountant => 'Saisit et valide les ventes, saisit les encaissements clients, lettre les clients.',
            self::PayrollManager => 'Saisit les opérations diverses de la paie ; lit la banque.',
            self::ChiefAccountant => 'Valide tous les journaux, tient les paramètres du dossier et les à-nouveaux.',
        };
    }
}
