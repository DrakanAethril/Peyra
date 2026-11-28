<?php

declare(strict_types=1);

namespace App\Service\Dossier;

use App\Entity\Dossier\Company;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Audit\AuditAction;
use App\Enum\Dossier\LegalForm;
use App\Enum\Dossier\ProfitTaxRegime;
use App\Enum\Dossier\VatRegime;
use App\Service\Audit\AuditTrail;
use App\Service\Shared\Money;

/**
 * The fiche société (design-ux.md § 3.2, « Société »): checks what a person typed, names every
 * fault in French under its field, and replaces the dossier's company when all is right - a change
 * the audit trail keeps. The SIRET is fictitious: fourteen digits, nothing asked of the État; a
 * VAT number, when given, carries the SIREN. The caller checks DossierVoter::EDIT and flushes.
 */
final readonly class CompanySheet
{
    public function __construct(private AuditTrail $auditTrail)
    {
    }

    /** The company, or null with `$draft->errors` filled. */
    public function check(CompanyDraft $draft): ?Company
    {
        $draft->errors = [];
        $denomination = self::oneLine($draft->denomination);
        if ('' === $denomination) {
            $draft->errors['denomination'] = 'Indiquez la dénomination de la société.';
        } elseif (mb_strlen($denomination) > 120) {
            $draft->errors['denomination'] = 'La dénomination tient en 120 caractères.';
        }
        $legalForm = LegalForm::tryFrom($draft->legalForm);
        if (null === $legalForm) {
            $draft->errors['legalForm'] = 'Choisissez la forme juridique.';
        }
        $capital = null;
        if (null !== $legalForm && !$legalForm->hasCapital()) {
            $capital = Money::zero();
        } elseif (null === $draft->capitalCents) {
            $draft->errors['capital'] = 'Indiquez le capital social.';
        } elseif ($draft->capitalCents < 0) {
            $draft->errors['capital'] = 'Le capital ne peut pas être négatif.';
        } else {
            $capital = Money::cents($draft->capitalCents);
        }
        $siret = self::digits($draft->siret);
        if (1 !== preg_match('/^\d{14}$/', $siret)) {
            $draft->errors['siret'] = 'Le SIRET compte 14 chiffres.';
        }
        $vatNumber = strtoupper(self::compact($draft->vatNumber));
        if ('' !== $vatNumber) {
            if (1 !== preg_match('/^FR[0-9A-Z]{2}\d{9}$/', $vatNumber)) {
                $draft->errors['vatNumber'] = 'Un numéro de TVA français s’écrit FR, deux caractères de clé, puis les 9 chiffres du SIREN.';
            } elseif (!isset($draft->errors['siret']) && substr($vatNumber, 4) !== substr($siret, 0, 9)) {
                $draft->errors['vatNumber'] = 'Le numéro de TVA reprend le SIREN, les 9 premiers chiffres du SIRET.';
            }
        }
        $nafCode = strtoupper(self::compact($draft->nafCode));
        if ('' !== $nafCode && 1 !== preg_match('/^\d{2}\.\d{2}[A-Z]$/', $nafCode)) {
            $draft->errors['nafCode'] = 'Le code NAF s’écrit comme 16.23Z.';
        }
        $activity = trim($draft->activity);
        if ('' === $activity) {
            $draft->errors['activity'] = 'Décrivez l’activité de la société.';
        } elseif (mb_strlen($activity) > 2000) {
            $draft->errors['activity'] = 'L’activité tient en 2 000 caractères.';
        }
        $street = self::oneLine($draft->street);
        if ('' === $street) {
            $draft->errors['street'] = 'Indiquez l’adresse.';
        } elseif (mb_strlen($street) > 120) {
            $draft->errors['street'] = 'Une ligne d’adresse tient en 120 caractères.';
        }
        $street2 = self::oneLine($draft->street2);
        if (mb_strlen($street2) > 120) {
            $draft->errors['street2'] = 'Une ligne d’adresse tient en 120 caractères.';
        }
        $postalCode = self::compact($draft->postalCode);
        if (1 !== preg_match('/^\d{5}$/', $postalCode)) {
            $draft->errors['postalCode'] = 'Le code postal compte 5 chiffres.';
        }
        $city = self::oneLine($draft->city);
        if ('' === $city) {
            $draft->errors['city'] = 'Indiquez la commune.';
        } elseif (mb_strlen($city) > 80) {
            $draft->errors['city'] = 'La commune tient en 80 caractères.';
        }
        $vatRegime = VatRegime::tryFrom($draft->vatRegime);
        if (null === $vatRegime) {
            $draft->errors['vatRegime'] = 'Choisissez le régime de TVA.';
        }
        $profitTaxRegime = ProfitTaxRegime::tryFrom($draft->profitTaxRegime);
        if (null === $profitTaxRegime) {
            $draft->errors['profitTaxRegime'] = 'Choisissez le régime fiscal.';
        }

        if ([] !== $draft->errors || null === $legalForm || null === $capital || null === $vatRegime || null === $profitTaxRegime) {
            return null;
        }

        return new Company($denomination, $legalForm, $capital, $siret, '' === $vatNumber ? null : $vatNumber, '' === $nafCode ? null : $nafCode, $activity, $street, '' === $street2 ? null : $street2, $postalCode, $city, $vatRegime, $profitTaxRegime);
    }

    /** Replaces the company; false when nothing changed (nothing is written). */
    public function save(Dossier $dossier, Company $company, User $by): bool
    {
        $before = self::fields($dossier->getCompany());
        $after = self::fields($company);
        $changed = array_keys(array_diff_assoc($after, $before));
        if ([] === $changed) {
            return false;
        }
        $dossier->describeCompany($company);
        $this->auditTrail->inDossier(
            $dossier,
            AuditAction::DossierCompanyChanged,
            $by,
            \sprintf('Fiche société de %s modifiée', $company->getName()),
            ['dossier' => $dossier->getId(), 'fields' => $changed],
        );

        return true;
    }

    /** @return array<string, string> */
    private static function fields(Company $company): array
    {
        return [
            'denomination' => $company->getDenomination(),
            'legalForm' => $company->getLegalForm()->value,
            'capital' => (string) $company->getCapital()->cents,
            'siret' => $company->getSiret(),
            'vatNumber' => $company->getVatNumber() ?? '',
            'nafCode' => $company->getNafCode() ?? '',
            'activity' => $company->getActivity(),
            'street' => $company->getStreet(),
            'street2' => $company->getStreet2() ?? '',
            'postalCode' => $company->getPostalCode(),
            'city' => $company->getCity(),
            'vatRegime' => $company->getVatRegime()->value,
            'profitTaxRegime' => $company->getProfitTaxRegime()->value,
        ];
    }

    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function compact(string $text): string
    {
        return (string) preg_replace('/\s+/u', '', $text);
    }

    private static function digits(string $text): string
    {
        return (string) preg_replace('/[\s.\-]+/u', '', $text);
    }
}
