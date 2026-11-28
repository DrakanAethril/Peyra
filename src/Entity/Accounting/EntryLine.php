<?php

declare(strict_types=1);

namespace App\Entity\Accounting;

use App\Entity\Dossier\DossierOwned;
use App\Entity\Dossier\DossierOwnedTrait;
use App\Entity\ThirdParty\ThirdParty;
use App\Repository\Accounting\EntryLineRepository;
use App\Service\Shared\Money;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A line of an entry: an account of the plan, the third party when the account is collective (or
 * the third party's own general account), a label, a debit or a credit - one of them, positive -,
 * and the due date of an invoice. Balances are the sum of these lines, never a stored counter.
 *
 * Made only by App\Service\Accounting\Ledger\Ledger. The lettering - its letter and the dossier's
 * date it was set on - is the one thing a validated entry's line may still take (step 1.6;
 * App\Doctrine\LedgerGuard), set and cleared by App\Service\Accounting\Lettering\Lettering alone
 * (LetteringDoorTest); a line of the brouillard carries none.
 */
#[ORM\Entity(repositoryClass: EntryLineRepository::class)]
#[ORM\Table(name: 'entry_line')]
#[ORM\Index(name: 'idx_entry_line_account', columns: ['account_id'])]
#[ORM\Index(name: 'idx_entry_line_third_party', columns: ['third_party_id'])]
class EntryLine implements DossierOwned
{
    use DossierOwnedTrait;

    /** The fields a validated entry's line may still change (lettering, step 1.6). */
    public const array MUTABLE_WHEN_VALIDATED = ['letter', 'letteredOn'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Entry $entry;

    /** 1, 2, 3… in the order typed. */
    #[ORM\Column]
    private int $position;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Account $account;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'RESTRICT')]
    private ?ThirdParty $thirdParty;

    #[ORM\Column(length: 200)]
    private string $label;

    #[ORM\Column(type: 'money')]
    private Money $debit;

    #[ORM\Column(type: 'money')]
    private Money $credit;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dueDate;

    /**
     * The lettering letter, continuous per account (or per third party on a collective): « A »,
     * « AB » in capitals for a group that balances, in small letters (« b ») for a partial one, as
     * Sage writes them (App\Service\Accounting\Lettering\LetterSequence).
     */
    #[ORM\Column(length: 4, nullable: true)]
    private ?string $letter = null;

    /** The dossier's date the line was lettered on (the FEC's DateLet), never the server's. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $letteredOn = null;

    public function __construct(Entry $entry, int $position, Account $account, ?ThirdParty $thirdParty, string $label, Money $debit, Money $credit, ?\DateTimeImmutable $dueDate)
    {
        $this->dossier = $entry->getDossier();
        $this->entry = $entry;
        $this->position = $position;
        $this->account = $account;
        $this->thirdParty = $thirdParty;
        $this->label = $label;
        $this->debit = $debit;
        $this->credit = $credit;
        $this->dueDate = $dueDate?->setTime(0, 0);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEntry(): Entry
    {
        return $this->entry;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getAccount(): Account
    {
        return $this->account;
    }

    public function getThirdParty(): ?ThirdParty
    {
        return $this->thirdParty;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getDebit(): Money
    {
        return $this->debit;
    }

    public function getCredit(): Money
    {
        return $this->credit;
    }

    public function getDueDate(): ?\DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function getLetter(): ?string
    {
        return $this->letter;
    }

    public function getLetteredOn(): ?\DateTimeImmutable
    {
        return $this->letteredOn;
    }

    public function isLettered(): bool
    {
        return null !== $this->letter;
    }

    /** Lettered in a group that does not balance yet (its letter in small letters). */
    public function isPartiallyLettered(): bool
    {
        return null !== $this->letter && mb_strtolower($this->letter) === $this->letter;
    }

    /** The one door of the lettering sets the letter of a validated line (Lettering, LetteringDoorTest). */
    public function letteringSet(string $letter, \DateTimeImmutable $on): void
    {
        $this->letter = $letter;
        $this->letteredOn = $on->setTime(0, 0);
    }

    /** The one door of the lettering clears it (« délettrer »). */
    public function letteringClear(): void
    {
        $this->letter = null;
        $this->letteredOn = null;
    }
}
