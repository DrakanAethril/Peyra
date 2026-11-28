<?php

declare(strict_types=1);

namespace App\Entity\Inventory;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierOwned;
use App\Entity\Dossier\DossierOwnedTrait;
use App\Entity\Sales\SalesDocument;
use App\Enum\Inventory\StockMovementKind;
use App\Repository\Inventory\StockMovementRepository;
use App\Service\Inventory\Stock\StockMove;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One movement of an article's stock (lot 2, step 2.5), dated in the dossier: its kind, its
 * quantity (three decimals at most; an adjustment's is signed, every other one positive), the
 * storage place it happens in (« Dépôt principal ») and, for a transfer, the one it goes to. An
 * entry carries its value - what came in cost; an exit's value is never stored: it is read by
 * replaying the article's movements with its method (StockValuation), so that a movement dated
 * earlier values what follows it again. A movement made by a validated delivery note or receipt
 * names it, an adjustment made by a validated physical inventory names that (step 2.5b). StockMovements is the only writer; a movement is never changed.
 */
#[ORM\Entity(repositoryClass: StockMovementRepository::class)]
#[ORM\Table(name: 'stock_movement')]
#[ORM\Index(name: 'idx_stock_movement_article', columns: ['dossier_id', 'article_id', 'date'])]
class StockMovement implements DossierOwned
{
    use DossierOwnedTrait;

    /** The storage place of every movement a document makes, and the one a form offers first. */
    public const string MAIN_LOCATION = 'Dépôt principal';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Article $article;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(length: 12, enumType: StockMovementKind::class)]
    private StockMovementKind $kind;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 3)]
    private string $quantity;

    /** An entry's cost; null for every other kind. */
    #[ORM\Column(type: 'money', nullable: true)]
    private ?Money $value;

    #[ORM\Column(length: 40)]
    private string $location;

    /** A transfer's destination; null for every other kind. */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $toLocation;

    #[ORM\Column(length: 160)]
    private string $label;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?SalesDocument $salesDocument;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?StockCount $stockCount;

    public function __construct(Dossier $dossier, Article $article, \DateTimeImmutable $date, StockMovementKind $kind, BigDecimal $quantity, ?Money $value, string $location, ?string $toLocation, string $label, ?SalesDocument $salesDocument = null, ?StockCount $stockCount = null)
    {
        $this->dossier = $dossier;
        $this->article = $article;
        $this->date = $date;
        $this->kind = $kind;
        $this->quantity = (string) $quantity->toScale(3);
        $this->value = $value;
        $this->location = $location;
        $this->toLocation = $toLocation;
        $this->label = $label;
        $this->salesDocument = $salesDocument;
        $this->stockCount = $stockCount;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getArticle(): Article
    {
        return $this->article;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getKind(): StockMovementKind
    {
        return $this->kind;
    }

    public function getQuantity(): BigDecimal
    {
        return BigDecimal::of($this->quantity);
    }

    public function getValue(): ?Money
    {
        return $this->value;
    }

    public function getLocation(): string
    {
        return $this->location;
    }

    public function getToLocation(): ?string
    {
        return $this->toLocation;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getSalesDocument(): ?SalesDocument
    {
        return $this->salesDocument;
    }

    public function getStockCount(): ?StockCount
    {
        return $this->stockCount;
    }

    /** What the valuation reads of it. */
    public function toMove(): StockMove
    {
        return new StockMove((int) $this->id, $this->date, $this->kind, $this->getQuantity(), $this->value, $this->location, $this->toLocation);
    }
}
