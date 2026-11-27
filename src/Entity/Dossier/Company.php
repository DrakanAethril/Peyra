<?php

declare(strict_types=1);

namespace App\Entity\Dossier;

use App\Enum\Dossier\LegalForm;
use App\Enum\Dossier\ProfitTaxRegime;
use App\Enum\Dossier\VatRegime;
use App\Service\Shared\Money;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The fictitious company a dossier keeps (fiche société, design-ux.md § 3.2): identity, address,
 * VAT regime and profit tax regime. Immutable: the fiche replaces it whole
 * (App\Service\Dossier\CompanySheet checks it first). The SIRET is fictitious - fourteen digits,
 * nothing asked of the État.
 */
#[ORM\Embeddable]
final class Company
{
    public function __construct(
        #[ORM\Column(length: 120)]
        private readonly string $denomination,
        #[ORM\Column(length: 16, enumType: LegalForm::class)]
        private readonly LegalForm $legalForm,
        #[ORM\Column(type: 'money')]
        private readonly Money $capital,
        #[ORM\Column(length: 14)]
        private readonly string $siret,
        #[ORM\Column(length: 16, nullable: true)]
        private readonly ?string $vatNumber,
        #[ORM\Column(length: 6, nullable: true)]
        private readonly ?string $nafCode,
        #[ORM\Column(type: Types::TEXT)]
        private readonly string $activity,
        #[ORM\Column(length: 120)]
        private readonly string $street,
        #[ORM\Column(length: 120, nullable: true)]
        private readonly ?string $street2,
        #[ORM\Column(length: 5)]
        private readonly string $postalCode,
        #[ORM\Column(length: 80)]
        private readonly string $city,
        #[ORM\Column(length: 32, enumType: VatRegime::class)]
        private readonly VatRegime $vatRegime,
        #[ORM\Column(length: 32, enumType: ProfitTaxRegime::class)]
        private readonly ProfitTaxRegime $profitTaxRegime,
    ) {
    }

    public function getDenomination(): string
    {
        return $this->denomination;
    }

    public function getLegalForm(): LegalForm
    {
        return $this->legalForm;
    }

    /** « Menuiserie Vasseur SARL » */
    public function getName(): string
    {
        return $this->denomination.' '.$this->legalForm->shortName();
    }

    public function getCapital(): Money
    {
        return $this->capital;
    }

    public function getSiret(): string
    {
        return $this->siret;
    }

    /** The first nine digits of the SIRET. */
    public function getSiren(): string
    {
        return substr($this->siret, 0, 9);
    }

    public function getVatNumber(): ?string
    {
        return $this->vatNumber;
    }

    public function getNafCode(): ?string
    {
        return $this->nafCode;
    }

    public function getActivity(): string
    {
        return $this->activity;
    }

    public function getStreet(): string
    {
        return $this->street;
    }

    public function getStreet2(): ?string
    {
        return $this->street2;
    }

    public function getPostalCode(): string
    {
        return $this->postalCode;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function getVatRegime(): VatRegime
    {
        return $this->vatRegime;
    }

    public function getProfitTaxRegime(): ProfitTaxRegime
    {
        return $this->profitTaxRegime;
    }
}
