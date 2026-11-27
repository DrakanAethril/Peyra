<?php

declare(strict_types=1);

namespace App\Service\Dossier;

use App\Entity\Dossier\Company;
use App\Service\Shared\FrenchFormat;

/**
 * The fiche société as a person typed it, before CompanySheet checks it: texts, the capital in
 * cents (Px:AmountInput), and the error of each field.
 */
final class CompanyDraft
{
    public string $denomination = '';
    public string $legalForm = '';
    public ?int $capitalCents = null;
    public string $siret = '';
    public string $vatNumber = '';
    public string $nafCode = '';
    public string $activity = '';
    public string $street = '';
    public string $street2 = '';
    public string $postalCode = '';
    public string $city = '';
    public string $vatRegime = '';
    public string $profitTaxRegime = '';

    /** @var array<string, string> by field */
    public array $errors = [];

    public static function of(Company $company): self
    {
        $draft = new self();
        $draft->denomination = $company->getDenomination();
        $draft->legalForm = $company->getLegalForm()->value;
        $draft->capitalCents = $company->getCapital()->cents;
        $draft->siret = FrenchFormat::siret($company->getSiret());
        $draft->vatNumber = $company->getVatNumber() ?? '';
        $draft->nafCode = $company->getNafCode() ?? '';
        $draft->activity = $company->getActivity();
        $draft->street = $company->getStreet();
        $draft->street2 = $company->getStreet2() ?? '';
        $draft->postalCode = $company->getPostalCode();
        $draft->city = $company->getCity();
        $draft->vatRegime = $company->getVatRegime()->value;
        $draft->profitTaxRegime = $company->getProfitTaxRegime()->value;

        return $draft;
    }

    public function error(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }
}
