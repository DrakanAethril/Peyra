<?php

declare(strict_types=1);

namespace App\Entity\Banking;

use App\Entity\Accounting\Entry;
use App\Entity\Accounting\Journal;
use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierOwned;
use App\Entity\Dossier\DossierOwnedTrait;
use App\Repository\Banking\ChequeDepositRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A remittance of cheques to the bank (« remise de chèques », step 2.6b): the cheques received
 * (Payment::$deposit) taken off 511200 into the bank journal's account in one entry, whose pièce is
 * the remittance's slip, « REM-0008 ». The bank credits its total in one line of the statement.
 */
#[ORM\Entity(repositoryClass: ChequeDepositRepository::class)]
#[ORM\Table(name: 'cheque_deposit')]
#[ORM\UniqueConstraint(name: 'uniq_cheque_deposit_number', columns: ['dossier_id', 'number'])]
class ChequeDeposit implements DossierOwned
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
    private \DateTimeImmutable $depositedOn;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Entry $entry = null;

    public function __construct(Dossier $dossier, Journal $journal, int $number, \DateTimeImmutable $depositedOn)
    {
        $this->dossier = $dossier;
        $this->journal = $journal;
        $this->number = $number;
        $this->depositedOn = $depositedOn;
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

    /** The slip's reference, the pièce of its entry. */
    public function getReference(): string
    {
        return \sprintf('REM-%04d', $this->number);
    }

    public function getDepositedOn(): \DateTimeImmutable
    {
        return $this->depositedOn;
    }

    public function getEntry(): ?Entry
    {
        return $this->entry;
    }

    public function linkEntry(Entry $entry): void
    {
        $this->entry = $entry;
    }
}
