<?php

declare(strict_types=1);

namespace App\Entity\Payroll;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierOwned;
use App\Entity\Dossier\DossierOwnedTrait;
use App\Enum\Payroll\PayrollBodyKind;
use App\Repository\Payroll\PayrollBodyRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A collecting body of the dossier (« organisme »: the Urssaf, the complementary pension fund, the
 * provident fund, the health insurer) - fictitious in every dossier, like the employees. The
 * contributions of the plan de paie name the one they are paid to. PayrollBodySheet is the one
 * writer.
 */
#[ORM\Entity(repositoryClass: PayrollBodyRepository::class)]
#[ORM\Table(name: 'payroll_body')]
class PayrollBody implements DossierOwned
{
    use DossierOwnedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: PayrollBodyKind::class)]
    private PayrollBodyKind $kind;

    #[ORM\Column(length: 80)]
    private string $name;

    /** The Urssaf account, the membership or contract number; empty until known. */
    #[ORM\Column(length: 40)]
    private string $reference;

    public function __construct(Dossier $dossier, PayrollBodyKind $kind, string $name, string $reference)
    {
        $this->dossier = $dossier;
        $this->kind = $kind;
        $this->name = $name;
        $this->reference = $reference;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKind(): PayrollBodyKind
    {
        return $this->kind;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    /** PayrollBodySheet is the caller. */
    public function describe(PayrollBodyKind $kind, string $name, string $reference): void
    {
        $this->kind = $kind;
        $this->name = $name;
        $this->reference = $reference;
    }
}
