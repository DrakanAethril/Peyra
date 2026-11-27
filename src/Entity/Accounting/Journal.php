<?php

declare(strict_types=1);

namespace App\Entity\Accounting;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierOwned;
use App\Entity\Dossier\DossierOwnedTrait;
use App\Enum\Accounting\JournalType;
use App\Repository\Accounting\JournalRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A journal of the dossier (« ACH », « BQ1 »…): its type, and for a bank or a cash journal the
 * counterpart account its entries balance against. Entries are numbered without a gap per journal
 * and per exercise (step 1.3). JournalSheet is the one writer.
 */
#[ORM\Entity(repositoryClass: JournalRepository::class)]
#[ORM\Table(name: 'journal')]
#[ORM\UniqueConstraint(name: 'uniq_journal_dossier_code', columns: ['dossier_id', 'code'])]
class Journal implements DossierOwned
{
    use DossierOwnedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** « BQ1 »: capitals and digits, 1 to 6. */
    #[ORM\Column(length: 6)]
    private string $code;

    #[ORM\Column(length: 80)]
    private string $label;

    #[ORM\Column(length: 16, enumType: JournalType::class)]
    private JournalType $type;

    /** A bank or a cash journal's counterpart (512…, 53…); an account that is one cannot be deleted. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'RESTRICT')]
    private ?Account $counterpartAccount;

    public function __construct(Dossier $dossier, string $code, string $label, JournalType $type, ?Account $counterpartAccount)
    {
        $this->dossier = $dossier;
        $this->code = $code;
        $this->label = $label;
        $this->type = $type;
        $this->counterpartAccount = $counterpartAccount;
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

    public function getType(): JournalType
    {
        return $this->type;
    }

    public function getCounterpartAccount(): ?Account
    {
        return $this->counterpartAccount;
    }

    /** JournalSheet is the caller: it checks the code, the type and the counterpart first. */
    public function describe(string $code, string $label, JournalType $type, ?Account $counterpartAccount): void
    {
        $this->code = $code;
        $this->label = $label;
        $this->type = $type;
        $this->counterpartAccount = $counterpartAccount;
    }
}
