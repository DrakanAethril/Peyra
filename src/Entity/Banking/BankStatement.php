<?php

declare(strict_types=1);

namespace App\Entity\Banking;

use App\Entity\Accounting\Journal;
use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierOwned;
use App\Entity\Dossier\DossierOwnedTrait;
use App\Repository\Banking\BankStatementRepository;
use App\Service\Shared\Money;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A bank statement of a bank journal (step 2.6a): its number, the days it covers, the balances the
 * bank printed - signed as the company reads them, positive when the account holds money - and
 * its lines. Imported whole, it never changes but for its lines' pointing and its reconciliation,
 * validated on a dossier's date and reopened while the next one is not. BankStatements and
 * Pointings are its only writers.
 */
#[ORM\Entity(repositoryClass: BankStatementRepository::class)]
#[ORM\Table(name: 'bank_statement')]
#[ORM\UniqueConstraint(name: 'uniq_bank_statement_number', columns: ['journal_id', 'number'])]
class BankStatement implements DossierOwned
{
    use DossierOwnedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Journal $journal;

    #[ORM\Column]
    private int $number;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $startsOn;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $endsOn;

    #[ORM\Column(type: 'money')]
    private Money $openingBalance;

    #[ORM\Column(type: 'money')]
    private Money $closingBalance;

    /** The dossier's date it was imported on. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $importedOn;

    /** The file it was read from (« releve-03.csv »). */
    #[ORM\Column(length: 120)]
    private string $source;

    /** The dossier's date the reconciliation was validated on; null while it is not. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reconciledOn = null;

    /** @var Collection<int, BankStatementLine> */
    #[ORM\OneToMany(targetEntity: BankStatementLine::class, mappedBy: 'statement', cascade: ['persist'])]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $lines;

    public function __construct(Dossier $dossier, Journal $journal, int $number, \DateTimeImmutable $startsOn, \DateTimeImmutable $endsOn, Money $openingBalance, \DateTimeImmutable $importedOn, string $source)
    {
        $this->dossier = $dossier;
        $this->journal = $journal;
        $this->number = $number;
        $this->startsOn = $startsOn;
        $this->endsOn = $endsOn;
        $this->openingBalance = $openingBalance;
        $this->closingBalance = $openingBalance;
        $this->importedOn = $importedOn;
        $this->source = $source;
        $this->lines = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJournal(): Journal
    {
        return $this->journal;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    /** « Relevé n° 11 ». */
    public function getTitle(): string
    {
        return 'Relevé n° '.$this->number;
    }

    public function getStartsOn(): \DateTimeImmutable
    {
        return $this->startsOn;
    }

    public function getEndsOn(): \DateTimeImmutable
    {
        return $this->endsOn;
    }

    public function getOpeningBalance(): Money
    {
        return $this->openingBalance;
    }

    public function getClosingBalance(): Money
    {
        return $this->closingBalance;
    }

    public function getImportedOn(): \DateTimeImmutable
    {
        return $this->importedOn;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getReconciledOn(): ?\DateTimeImmutable
    {
        return $this->reconciledOn;
    }

    public function isReconciled(): bool
    {
        return null !== $this->reconciledOn;
    }

    /** @return list<BankStatementLine> in the statement's order */
    public function getLines(): array
    {
        return array_values($this->lines->toArray());
    }

    /** A line read from the file; the closing balance follows. */
    public function addLine(\DateTimeImmutable $date, string $label, Money $amount): BankStatementLine
    {
        $line = new BankStatementLine($this, $this->lines->count() + 1, $date, $label, $amount);
        $this->lines->add($line);
        $this->closingBalance = $this->closingBalance->plus($amount);

        return $line;
    }

    public function reconcile(\DateTimeImmutable $on): void
    {
        $this->reconciledOn = $on;
    }

    public function reopen(): void
    {
        $this->reconciledOn = null;
    }
}
