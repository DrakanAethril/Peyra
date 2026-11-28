<?php

declare(strict_types=1);

namespace App\Entity\Banking;

use App\Entity\Dossier\DossierOwned;
use App\Entity\Dossier\DossierOwnedTrait;
use App\Service\Shared\Money;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A line of a bank statement: the bank's date and wording, its amount signed as the company reads
 * it (positive: money in, the bank's « crédit »), and the letter of the pointing it belongs to.
 */
#[ORM\Entity]
#[ORM\Table(name: 'bank_statement_line')]
class BankStatementLine implements DossierOwned
{
    use DossierOwnedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private BankStatement $statement;

    #[ORM\Column]
    private int $position;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(length: 160)]
    private string $label;

    #[ORM\Column(type: 'money')]
    private Money $amount;

    #[ORM\Column(length: 4, nullable: true)]
    private ?string $letter = null;

    public function __construct(BankStatement $statement, int $position, \DateTimeImmutable $date, string $label, Money $amount)
    {
        $this->dossier = $statement->getDossier();
        $this->statement = $statement;
        $this->position = $position;
        $this->date = $date;
        $this->label = $label;
        $this->amount = $amount;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStatement(): BankStatement
    {
        return $this->statement;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getAmount(): Money
    {
        return $this->amount;
    }

    public function getLetter(): ?string
    {
        return $this->letter;
    }

    /** Pointings::point() and unpoint() alone. */
    public function pointingSet(?string $letter): void
    {
        $this->letter = $letter;
    }
}
