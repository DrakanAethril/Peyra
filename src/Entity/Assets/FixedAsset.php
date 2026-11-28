<?php

declare(strict_types=1);

namespace App\Entity\Assets;

use App\Entity\Accounting\Account;
use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierOwned;
use App\Entity\Dossier\DossierOwnedTrait;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Assets\AssetOrigin;
use App\Enum\Assets\DepreciationMethod;
use App\Repository\Assets\FixedAssetRepository;
use App\Service\Assets\FixedAssetValues;
use App\Service\Shared\Money;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A fixed asset of the dossier (« fiche d'immobilisation », lot 3, step 3.4): its account and its
 * depreciation account, how it entered the company and when it was put into service, its cost
 * (price before VAT and incidental costs; the VAT paid beside it), how it is depreciated - with,
 * for the simple approach by components, the components' own costs and lengths, and for the units
 * of work the units consumed by exercise. Its plan is never stored: DepreciationPlan reads it.
 * FixedAssetSheet is the one writer.
 */
#[ORM\Entity(repositoryClass: FixedAssetRepository::class)]
#[ORM\Table(name: 'fixed_asset')]
#[ORM\UniqueConstraint(name: 'uniq_fixed_asset_dossier_code', columns: ['dossier_id', 'code'])]
class FixedAsset implements DossierOwned
{
    use DossierOwnedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** « I13 »: capitals and digits, 1 to 12. */
    #[ORM\Column(length: 12)]
    private string $code = '';

    #[ORM\Column(length: 120)]
    private string $label = '';

    #[ORM\Column(length: 12, enumType: AssetOrigin::class)]
    private AssetOrigin $origin = AssetOrigin::Purchase;

    /** The asset account (class 2); an account that holds a fixed asset is not deleted. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Account $account;

    /** Its depreciation account (28…), when it is depreciated. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'RESTRICT')]
    private ?Account $depreciationAccount = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $acquiredOn;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $inServiceOn;

    /** The price before VAT, or the cost of production. */
    #[ORM\Column(type: 'money')]
    private Money $price;

    /** Incidental costs part of the cost (transport, installation, training…), before VAT. */
    #[ORM\Column(type: 'money')]
    private Money $incidentalCosts;

    /** The VAT paid on the acquisition. */
    #[ORM\Column(type: 'money')]
    private Money $vat;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?ThirdParty $supplier = null;

    /** The pièce of the acquisition (« MBI-2026-0311 »). */
    #[ORM\Column(length: 40)]
    private string $document = '';

    #[ORM\Column(type: 'money')]
    private Money $residualValue;

    #[ORM\Column(length: 12, enumType: DepreciationMethod::class)]
    private DepreciationMethod $method = DepreciationMethod::Linear;

    /** The years of use (linear, declining); null for the units of work, by components, or not depreciated. */
    #[ORM\Column(nullable: true)]
    private ?int $years = null;

    /** The units of work the asset will give, for that method. */
    #[ORM\Column(nullable: true)]
    private ?int $totalUnits = null;

    /** @var array<string, int> the units consumed, by the last day of an exercise (Y-m-d) */
    #[ORM\Column(type: Types::JSON)]
    private array $unitsUsed = [];

    /** A fiscal declining plan beside the linear one: the gap is the dérogatoire. */
    #[ORM\Column]
    private bool $fiscalDeclining = false;

    /** @var list<array{label: string, cost: int, years: int}> the components (approach by components), costs in cents */
    #[ORM\Column(type: Types::JSON)]
    private array $components = [];

    public function __construct(Dossier $dossier, Account $account, \DateTimeImmutable $acquiredOn)
    {
        $this->dossier = $dossier;
        $this->account = $account;
        $this->acquiredOn = $acquiredOn;
        $this->inServiceOn = $acquiredOn;
        $this->price = Money::zero();
        $this->incidentalCosts = Money::zero();
        $this->vat = Money::zero();
        $this->residualValue = Money::zero();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getOrigin(): AssetOrigin
    {
        return $this->origin;
    }

    public function getAccount(): Account
    {
        return $this->account;
    }

    public function getDepreciationAccount(): ?Account
    {
        return $this->depreciationAccount;
    }

    public function getAcquiredOn(): \DateTimeImmutable
    {
        return $this->acquiredOn;
    }

    public function getInServiceOn(): \DateTimeImmutable
    {
        return $this->inServiceOn;
    }

    public function getPrice(): Money
    {
        return $this->price;
    }

    public function getIncidentalCosts(): Money
    {
        return $this->incidentalCosts;
    }

    /** The cost: the price and the incidental costs. */
    public function getCost(): Money
    {
        return $this->price->plus($this->incidentalCosts);
    }

    public function getVat(): Money
    {
        return $this->vat;
    }

    public function getSupplier(): ?ThirdParty
    {
        return $this->supplier;
    }

    public function getDocument(): string
    {
        return $this->document;
    }

    public function getResidualValue(): Money
    {
        return $this->residualValue;
    }

    public function getMethod(): DepreciationMethod
    {
        return $this->method;
    }

    public function getYears(): ?int
    {
        return $this->years;
    }

    public function getTotalUnits(): ?int
    {
        return $this->totalUnits;
    }

    /** @return array<string, int> */
    public function getUnitsUsed(): array
    {
        return $this->unitsUsed;
    }

    public function isFiscalDeclining(): bool
    {
        return $this->fiscalDeclining;
    }

    /** @return list<array{label: string, cost: int, years: int}> */
    public function getComponents(): array
    {
        return $this->components;
    }

    /** Everything at once, as FixedAssetSheet checked it. */
    public function describe(FixedAssetValues $values): void
    {
        $this->code = $values->code;
        $this->label = $values->label;
        $this->origin = $values->origin;
        $this->account = $values->account;
        $this->depreciationAccount = $values->depreciationAccount;
        $this->acquiredOn = $values->acquiredOn;
        $this->inServiceOn = $values->inServiceOn;
        $this->price = $values->price;
        $this->incidentalCosts = $values->incidentalCosts;
        $this->vat = $values->vat;
        $this->supplier = $values->supplier;
        $this->document = $values->document;
        $this->residualValue = $values->residualValue;
        $this->method = $values->method;
        $this->years = $values->years;
        $this->totalUnits = $values->totalUnits;
        $this->unitsUsed = $values->unitsUsed;
        $this->fiscalDeclining = $values->fiscalDeclining;
        $this->components = $values->components;
    }
}
