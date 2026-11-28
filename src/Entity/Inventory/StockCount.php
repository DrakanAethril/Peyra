<?php

declare(strict_types=1);

namespace App\Entity\Inventory;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierOwned;
use App\Entity\Dossier\DossierOwnedTrait;
use App\Enum\Inventory\StockCountState;
use App\Repository\Inventory\StockCountRepository;
use App\Service\Inventory\Stock\StockCountLine;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A physical inventory of one storage place at a day (step 2.5b): a line per article whose stock is
 * followed when it was opened, each with the quantity counted and the reason given for a gap. While
 * in progress the counts are saved at will; validated, each gap has become an adjustment
 * (StockMovement::$stockCount names it), the theoretical quantities are frozen on the lines, and it
 * never changes again. StockCounts is the only writer.
 */
#[ORM\Entity(repositoryClass: StockCountRepository::class)]
#[ORM\Table(name: 'stock_count')]
class StockCount implements DossierOwned
{
    use DossierOwnedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(length: 40)]
    private string $location;

    #[ORM\Column(length: 12, enumType: StockCountState::class)]
    private StockCountState $state = StockCountState::Draft;

    /** @var list<array<mixed>> StockCountLine::toArray() (`lines` is a MariaDB keyword) */
    #[ORM\Column(name: 'count_lines', type: Types::JSON)]
    private array $lines = [];

    /** @param list<StockCountLine> $lines */
    public function __construct(Dossier $dossier, \DateTimeImmutable $date, string $location, array $lines)
    {
        $this->dossier = $dossier;
        $this->date = $date;
        $this->location = $location;
        $this->write($lines);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getLocation(): string
    {
        return $this->location;
    }

    public function getState(): StockCountState
    {
        return $this->state;
    }

    public function isDraft(): bool
    {
        return StockCountState::Draft === $this->state;
    }

    /** « Inventaire du 31/03/2026 · Dépôt principal ». */
    public function getTitle(): string
    {
        return \sprintf('Inventaire du %s · %s', $this->date->format('d/m/Y'), $this->location);
    }

    /** @return list<StockCountLine> */
    public function getLines(): array
    {
        return array_map(StockCountLine::fromArray(...), $this->lines);
    }

    /** @param list<StockCountLine> $lines */
    public function recount(array $lines): void
    {
        $this->assertDraft();
        $this->write($lines);
    }

    /** @param list<StockCountLine> $lines the counts with their theoretical quantities frozen */
    public function validate(array $lines): void
    {
        $this->assertDraft();
        $this->write($lines);
        $this->state = StockCountState::Validated;
    }

    /** @param list<StockCountLine> $lines */
    private function write(array $lines): void
    {
        $this->lines = array_map(static fn (StockCountLine $line): array => $line->toArray(), $lines);
    }

    private function assertDraft(): void
    {
        if (!$this->isDraft()) {
            throw new \LogicException('A validated inventory never changes.');
        }
    }
}
