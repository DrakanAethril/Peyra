<?php

declare(strict_types=1);

namespace App\Entity\Assets;

use App\Entity\Accounting\Account;
use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierOwned;
use App\Entity\Dossier\DossierOwnedTrait;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Assets\AssetOrigin;
use App\Enum\Assets\AssetPostingKind;
use App\Enum\Assets\DepreciationMethod;
use App\Enum\Assets\DisposalKind;
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
 * of work the units consumed by exercise, and the impairment's balance (« dépréciation ») at the end of
 * an exercise. Its plan is never stored: DepreciationPlan reads it. Its way out - a sale (« cession »)
 * or a scrapping (« mise au rebut »), its day, price, VAT and buyer (step 3.4b) - and the pièces of the
 * entries the module asked for (`postings`, what FixedAssetPostings finds them by: the books are read,
 * never these). FixedAssetSheet is the one writer of the fiche, FixedAssetDisposals of its way out.
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

    /** @var array<string, int> the impairment's balance, by the last day of an exercise (Y-m-d), in cents */
    #[ORM\Column(type: Types::JSON)]
    private array $impairments = [];

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $disposedOn = null;

    #[ORM\Column(length: 12, nullable: true, enumType: DisposalKind::class)]
    private ?DisposalKind $disposalKind = null;

    /** The sale price before VAT; zero for a scrapping. */
    #[ORM\Column(type: 'money')]
    private Money $disposalPrice;

    /** The VAT invoiced on the sale price. */
    #[ORM\Column(type: 'money')]
    private Money $disposalVat;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?ThirdParty $buyer = null;

    /** @var list<array{kind: string, document: string, on: string}> the entries asked for, by pièce and day (Y-m-d) */
    #[ORM\Column(type: Types::JSON)]
    private array $postings = [];

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
        $this->disposalPrice = Money::zero();
        $this->disposalVat = Money::zero();
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

    /** @return array<string, int> */
    public function getImpairments(): array
    {
        return $this->impairments;
    }

    /** @return array<string, Money> the impairment's balances, by the last day of an exercise */
    public function impairmentBalances(): array
    {
        return array_map(static fn (int $cents): Money => Money::cents($cents), $this->impairments);
    }

    public function getDisposedOn(): ?\DateTimeImmutable
    {
        return $this->disposedOn;
    }

    public function getDisposalKind(): ?DisposalKind
    {
        return $this->disposalKind;
    }

    public function getDisposalPrice(): Money
    {
        return $this->disposalPrice;
    }

    public function getDisposalVat(): Money
    {
        return $this->disposalVat;
    }

    public function getBuyer(): ?ThirdParty
    {
        return $this->buyer;
    }

    /** Disposed of by the end of that day. */
    public function isDisposedBy(\DateTimeImmutable $day): bool
    {
        return null !== $this->disposedOn && $this->disposedOn <= $day;
    }

    /** The sale or the scrapping, as FixedAssetDisposals checked it. */
    public function dispose(DisposalKind $kind, \DateTimeImmutable $on, Money $price, Money $vat, ?ThirdParty $buyer): void
    {
        $this->disposalKind = $kind;
        $this->disposedOn = $on;
        $this->disposalPrice = $price;
        $this->disposalVat = $vat;
        $this->buyer = $buyer;
    }

    public function cancelDisposal(): void
    {
        $this->disposalKind = null;
        $this->disposedOn = null;
        $this->disposalPrice = Money::zero();
        $this->disposalVat = Money::zero();
        $this->buyer = null;
    }

    /** @return list<array{kind: string, document: string, on: string}> */
    public function getPostings(): array
    {
        return $this->postings;
    }

    /** The pièce of an entry the module asked for (FixedAssetPostings). */
    public function recordPosting(AssetPostingKind $kind, string $document, \DateTimeImmutable $on): void
    {
        $posting = ['kind' => $kind->value, 'document' => $document, 'on' => $on->format('Y-m-d')];
        if (!\in_array($posting, $this->postings, true)) {
            $this->postings[] = $posting;
        }
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
        $this->impairments = $values->impairments;
    }
}
