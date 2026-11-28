<?php

declare(strict_types=1);

namespace App\Service\Dossier;

use App\Entity\Dossier\Company;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\PostValue;
use Symfony\Component\HttpFoundation\Request;

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

    /** The fiche's fields of a posted form (dossier/_company_fields.html.twig). */
    public static function fromRequest(Request $request): self
    {
        $draft = new self();
        $draft->denomination = PostValue::trimmed($request, 'denomination');
        $draft->legalForm = PostValue::trimmed($request, 'legalForm');
        $draft->siret = PostValue::trimmed($request, 'siret');
        $draft->vatNumber = PostValue::trimmed($request, 'vatNumber');
        $draft->nafCode = PostValue::trimmed($request, 'nafCode');
        $draft->activity = PostValue::trimmed($request, 'activity');
        $draft->street = PostValue::trimmed($request, 'street');
        $draft->street2 = PostValue::trimmed($request, 'street2');
        $draft->postalCode = PostValue::trimmed($request, 'postalCode');
        $draft->city = PostValue::trimmed($request, 'city');
        $draft->vatRegime = PostValue::trimmed($request, 'vatRegime');
        $draft->profitTaxRegime = PostValue::trimmed($request, 'profitTaxRegime');
        $draft->capitalCents = PostValue::cents($request, 'capital');

        return $draft;
    }

    public function error(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }
}
