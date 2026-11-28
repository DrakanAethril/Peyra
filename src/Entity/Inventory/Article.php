<?php

declare(strict_types=1);

namespace App\Entity\Inventory;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierOwned;
use App\Entity\Dossier\DossierOwnedTrait;
use App\Enum\Inventory\StockValuationMethod;
use App\Repository\Inventory\ArticleRepository;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An article of the dossier (lot 2, step 2.3): its reference, what it is, its unit, its selling
 * price excluding tax, the VAT rate it is usually sold at (« 20 », « 5.5 »: the author writes it,
 * no rate is in the code), the revenue account its sales go to (a number of the plan, as text,
 * checked when the article is saved) and whether its stock is followed; a followed stock has its
 * valuation method and may have a minimum below which it is signalled (step 2.5). A raw material
 * has no selling price. ArticleSheet is the only writer.
 */
#[ORM\Entity(repositoryClass: ArticleRepository::class)]
#[ORM\Table(name: 'article')]
#[ORM\UniqueConstraint(name: 'uniq_article_dossier_code', columns: ['dossier_id', 'code'])]
class Article implements DossierOwned
{
    use DossierOwnedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** « PF001 »: 1 to 12 capitals or digits. */
    #[ORM\Column(length: 12)]
    private string $code;

    #[ORM\Column(length: 160)]
    private string $designation;

    /** « pièce », « m³ », « heure ». */
    #[ORM\Column(length: 20)]
    private string $unit;

    #[ORM\Column(type: 'money', nullable: true)]
    private ?Money $salePrice = null;

    /** The supplier's last price excluding tax: it fills a purchasing document's line (step 2.4); null when the article is not bought. */
    #[ORM\Column(type: 'money', nullable: true)]
    private ?Money $purchasePrice = null;

    /** VatRate's canonical text: « 20 », « 5.5 »; null when the article is neither sold nor bought. */
    #[ORM\Column(length: 8, nullable: true)]
    private ?string $vatRate = null;

    /** « 701000 »: a class-7 account of the plan. */
    #[ORM\Column(length: 8, nullable: true)]
    private ?string $revenueAccount = null;

    #[ORM\Column]
    private bool $stockTracked = false;

    #[ORM\Column(length: 20, enumType: StockValuationMethod::class)]
    private StockValuationMethod $valuationMethod = StockValuationMethod::WeightedAverage;

    /** The quantity below which its stock is signalled as running out (step 2.5); null: no threshold. */
    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 3, nullable: true)]
    private ?string $minimumStock = null;

    public function __construct(Dossier $dossier, string $code)
    {
        $this->dossier = $dossier;
        $this->code = $code;
        $this->designation = '';
        $this->unit = '';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getDesignation(): string
    {
        return $this->designation;
    }

    public function getUnit(): string
    {
        return $this->unit;
    }

    public function getSalePrice(): ?Money
    {
        return $this->salePrice;
    }

    public function getPurchasePrice(): ?Money
    {
        return $this->purchasePrice;
    }

    public function getVatRate(): ?string
    {
        return $this->vatRate;
    }

    public function getRevenueAccount(): ?string
    {
        return $this->revenueAccount;
    }

    public function isStockTracked(): bool
    {
        return $this->stockTracked;
    }

    public function getValuationMethod(): StockValuationMethod
    {
        return $this->valuationMethod;
    }

    public function getMinimumStock(): ?BigDecimal
    {
        return null === $this->minimumStock ? null : BigDecimal::of($this->minimumStock);
    }

    /** ArticleSheet's write, every field at once. */
    public function describe(string $code, string $designation, string $unit, ?Money $salePrice, ?string $vatRate, ?string $revenueAccount, bool $stockTracked, ?Money $purchasePrice = null, StockValuationMethod $valuationMethod = StockValuationMethod::WeightedAverage, ?BigDecimal $minimumStock = null): void
    {
        $this->valuationMethod = $valuationMethod;
        $this->minimumStock = null === $minimumStock ? null : (string) $minimumStock->toScale(3);
        $this->purchasePrice = $purchasePrice;
        $this->code = $code;
        $this->designation = $designation;
        $this->unit = $unit;
        $this->salePrice = $salePrice;
        $this->vatRate = $vatRate;
        $this->revenueAccount = $revenueAccount;
        $this->stockTracked = $stockTracked;
    }
}
