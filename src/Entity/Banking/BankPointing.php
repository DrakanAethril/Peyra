<?php

declare(strict_types=1);

namespace App\Entity\Banking;

use App\Entity\Accounting\Entry;
use App\Entity\Dossier\DossierOwned;
use App\Entity\Dossier\DossierOwnedTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The books' half of a pointing (step 2.6a): an entry whose movement on the statement's bank account
 * is pointed in that statement under a letter - the statement lines of the same letter are the
 * other half. An entry, not a line: a draft's lines are rewritten whole, its id stays. Deleting the
 * entry (a draft discarded) deletes the row; the group it left is then read as broken
 * (ReconciliationRules) until it is unpointed.
 */
#[ORM\Entity]
#[ORM\Table(name: 'bank_pointing')]
#[ORM\UniqueConstraint(name: 'uniq_bank_pointing_entry', columns: ['statement_id', 'entry_id'])]
class BankPointing implements DossierOwned
{
    use DossierOwnedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private BankStatement $statement;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Entry $entry;

    #[ORM\Column(type: Types::STRING, length: 4)]
    private string $letter;

    public function __construct(BankStatement $statement, Entry $entry, string $letter)
    {
        $this->dossier = $statement->getDossier();
        $this->statement = $statement;
        $this->entry = $entry;
        $this->letter = $letter;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStatement(): BankStatement
    {
        return $this->statement;
    }

    public function getEntry(): Entry
    {
        return $this->entry;
    }

    public function getLetter(): string
    {
        return $this->letter;
    }
}
