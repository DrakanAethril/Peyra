<?php

declare(strict_types=1);

namespace App\Service\Dossier\Rights;

use App\Enum\Dossier\AccessLevel;
use App\Enum\Dossier\DossierArea;
use App\Enum\Dossier\DossierProfile;

/**
 * The rights of each profile, area by area (lot 1, step 1.7; DECISIONS 1.7): a pure function, the
 * one place they are written. The accountants validate their own journal and enter the payments,
 * the chief accountant validates them all and alone changes the dossier's settings - the
 * segregation of duties of P1.7: whoever enters an invoice does not create the supplier it is paid
 * to. The payroll is the payroll manager's and the chief accountant's, closed to the two
 * accountants (step 4.1). No profile (an individual dossier, a teacher, a member not yet given one): every right.
 */
final class ProfileMatrix
{
    public static function level(?DossierProfile $profile, DossierArea $area): AccessLevel
    {
        return match ($profile) {
            null, DossierProfile::ChiefAccountant => $area->topLevel(),
            DossierProfile::SupplierAccountant => match ($area) {
                DossierArea::Purchases => AccessLevel::Validate,
                DossierArea::Sales, DossierArea::Payroll => AccessLevel::Closed,
                DossierArea::Treasury => AccessLevel::Enter,
                DossierArea::General, DossierArea::Settings => AccessLevel::Read,
            },
            DossierProfile::CustomerAccountant => match ($area) {
                DossierArea::Purchases, DossierArea::Payroll => AccessLevel::Closed,
                DossierArea::Sales => AccessLevel::Validate,
                DossierArea::Treasury => AccessLevel::Enter,
                DossierArea::General, DossierArea::Settings => AccessLevel::Read,
            },
            DossierProfile::PayrollManager => match ($area) {
                DossierArea::Purchases, DossierArea::Sales => AccessLevel::Closed,
                DossierArea::Treasury, DossierArea::Settings => AccessLevel::Read,
                DossierArea::General => AccessLevel::Enter,
                DossierArea::Payroll => AccessLevel::Validate,
            },
        };
    }

    /**
     * Why a profile may not do something, in the words of design-ux.md § 6.1 (« Votre profil
     * “Comptable fournisseurs” ne permet pas de valider le journal de banque. »), null when it may.
     *
     * @param string $gesture the verb and its object: « valider le journal de banque »
     */
    public static function refusal(?DossierProfile $profile, DossierArea $area, AccessLevel $wanted, string $gesture): ?string
    {
        if (null === $profile || self::level($profile, $area)->allows($wanted)) {
            return null;
        }

        return \sprintf('Votre profil “%s” ne permet pas de %s.', $profile->label(), $gesture);
    }
}
