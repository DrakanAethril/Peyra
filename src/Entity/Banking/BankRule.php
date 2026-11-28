<?php

declare(strict_types=1);

namespace App\Entity\Banking;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierOwned;
use App\Entity\Dossier\DossierOwnedTrait;
use App\Repository\Banking\BankRuleRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * An assignment rule (« règle d'affectation », design-ux.md § 3.2): when a statement line's wording
 * contains `pattern`, its entry goes to `account` - a number, or a third party's auxiliary code,
 * kept as typed like the entry templates' cells -, the VAT at `vatRate` (percent) split off when
 * one is given. The reconciliation's panel proposes it (BankRuleMatch); BankRules writes it.
 */
#[ORM\Entity(repositoryClass: BankRuleRepository::class)]
#[ORM\Table(name: 'bank_rule')]
class BankRule implements DossierOwned
{
    use DossierOwnedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 60)]
    private string $pattern;

    #[ORM\Column(length: 17)]
    private string $account;

    /** « 20 », « 5.5 » (VatRate::canonical()), or null. */
    #[ORM\Column(length: 7, nullable: true)]
    private ?string $vatRate;

    #[ORM\Column(length: 60)]
    private string $entryLabel;

    public function __construct(Dossier $dossier, string $pattern, string $account, ?string $vatRate, string $entryLabel)
    {
        $this->dossier = $dossier;
        $this->pattern = $pattern;
        $this->account = $account;
        $this->vatRate = $vatRate;
        $this->entryLabel = $entryLabel;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPattern(): string
    {
        return $this->pattern;
    }

    public function getAccount(): string
    {
        return $this->account;
    }

    public function getVatRate(): ?string
    {
        return $this->vatRate;
    }

    /** The label its entries take; empty: the statement line's own wording. */
    public function getEntryLabel(): string
    {
        return $this->entryLabel;
    }

    public function describe(string $pattern, string $account, ?string $vatRate, string $entryLabel): void
    {
        $this->pattern = $pattern;
        $this->account = $account;
        $this->vatRate = $vatRate;
        $this->entryLabel = $entryLabel;
    }
}
