<?php

declare(strict_types=1);

namespace App\Service\ThirdParty;

use App\Entity\ThirdParty\ThirdParty;

/**
 * A third party as a person typed it (or as a CSV line gave it), before ThirdPartyRules checks it:
 * the error of each field, and the notice that does not stop the save (an auxiliary code given by
 * the application).
 */
final class ThirdPartyDraft
{
    public string $code = '';
    public string $kind = '';
    public string $name = '';
    public bool $individual = false;
    /** The account's number as typed (the account picker fills it); empty: kept on no account. */
    public string $account = '';
    /** Empty on a collective account: one is proposed (AuxiliaryCode::suggest()). */
    public string $auxiliaryCode = '';
    public string $siren = '';
    public string $vatNumber = '';
    public string $street = '';
    public string $street2 = '';
    public string $postalCode = '';
    public string $city = '';
    public string $country = 'FR';
    public string $contactName = '';
    public string $email = '';
    public string $phone = '';
    public string $termKind = '';
    public string $termDays = '';
    public string $termDay = '';
    public string $paymentMethod = '';
    public string $note = '';

    /** @var array<string, string> by field */
    public array $errors = [];

    public ?string $notice = null;

    public static function of(ThirdParty $thirdParty): self
    {
        $draft = new self();
        $draft->code = $thirdParty->getCode();
        $draft->kind = $thirdParty->getKind()->value;
        $draft->name = $thirdParty->getName();
        $draft->individual = $thirdParty->isIndividual();
        $draft->account = $thirdParty->getAccount()?->getNumber() ?? '';
        $draft->auxiliaryCode = $thirdParty->getAuxiliaryCode() ?? '';
        $draft->siren = $thirdParty->getSiren() ?? '';
        $draft->vatNumber = $thirdParty->getVatNumber() ?? '';
        $draft->street = $thirdParty->getStreet() ?? '';
        $draft->street2 = $thirdParty->getStreet2() ?? '';
        $draft->postalCode = $thirdParty->getPostalCode() ?? '';
        $draft->city = $thirdParty->getCity() ?? '';
        $draft->country = $thirdParty->getCountry();
        $draft->contactName = $thirdParty->getContactName() ?? '';
        $draft->email = $thirdParty->getEmail() ?? '';
        $draft->phone = $thirdParty->getPhone() ?? '';
        $terms = $thirdParty->getPaymentTerms();
        $draft->termKind = $terms->getKind()->value;
        $draft->termDays = null === $terms->getDays() ? '' : (string) $terms->getDays();
        $draft->termDay = null === $terms->getDayOfMonth() ? '' : (string) $terms->getDayOfMonth();
        $draft->paymentMethod = $thirdParty->getPaymentMethod()->value;
        $draft->note = $thirdParty->getNote() ?? '';

        return $draft;
    }

    public function error(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }
}
