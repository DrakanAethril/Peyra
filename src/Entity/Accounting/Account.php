<?php

declare(strict_types=1);

namespace App\Entity\Accounting;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierOwned;
use App\Entity\Dossier\DossierOwnedTrait;
use App\Enum\Accounting\AccountNature;
use App\Enum\Accounting\AccountOrigin;
use App\Repository\Accounting\AccountRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * An account of the dossier's plan (« plan de comptes »): a general account of 6 to 8 digits,
 * copied from the reference chart when the dossier is made (ChartInstaller) or added by the
 * dossier (AccountSheet, an import). Third parties are not accounts: « 401SCIE » is a third
 * party's auxiliary code on a collective account (step 1.2b).
 *
 * Its class and its nature are read from its number, never typed. No balance is kept here: a
 * balance is a reading of the entry lines (README of the plan, § 8).
 */
#[ORM\Entity(repositoryClass: AccountRepository::class)]
#[ORM\Table(name: 'account')]
#[ORM\UniqueConstraint(name: 'uniq_account_dossier_number', columns: ['dossier_id', 'number'])]
class Account implements DossierOwned
{
    use DossierOwnedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** « 601000 »: digits only, 6 to 8 of them (AccountNumber). */
    #[ORM\Column(length: 8)]
    private string $number;

    #[ORM\Column(length: 200)]
    private string $label;

    /** Its entries are matched against each other (lettrage). */
    #[ORM\Column]
    private bool $lettrable;

    /** A third parties' account (« collectif »): each of its lines names a third party. */
    #[ORM\Column]
    private bool $collective;

    #[ORM\Column(length: 16, enumType: AccountOrigin::class)]
    private AccountOrigin $origin;

    public function __construct(Dossier $dossier, string $number, string $label, bool $lettrable, bool $collective, AccountOrigin $origin)
    {
        $this->dossier = $dossier;
        $this->number = $number;
        $this->label = $label;
        $this->lettrable = $lettrable;
        $this->collective = $collective;
        $this->origin = $origin;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    /** 1 to 8, the first digit. */
    public function getClassDigit(): int
    {
        return (int) $this->number[0];
    }

    public function getNature(): AccountNature
    {
        return AccountNature::of($this->number);
    }

    public function isLettrable(): bool
    {
        return $this->lettrable;
    }

    public function isCollective(): bool
    {
        return $this->collective;
    }

    public function getOrigin(): AccountOrigin
    {
        return $this->origin;
    }

    /** AccountSheet and the import are the callers: they check the number and the flags first. */
    public function describe(string $number, string $label, bool $lettrable, bool $collective): void
    {
        $this->number = $number;
        $this->label = $label;
        $this->lettrable = $lettrable;
        $this->collective = $collective;
    }
}
