<?php

declare(strict_types=1);

namespace App\Entity\Payroll;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierOwned;
use App\Entity\Dossier\DossierOwnedTrait;
use App\Repository\Payroll\PayrollMonthClosureRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A month of pay closed (step 4.4b; design-ux.md § 4.7): its payslips are all validated and no
 * longer change, and the month after it is the one paid. Closed on the dossier's date; reopened by a
 * teacher only, the last one first, which deletes the row (the audit trail keeps both gestures).
 * PayslipBook is the one writer.
 */
#[ORM\Entity(repositoryClass: PayrollMonthClosureRepository::class)]
#[ORM\Table(name: 'payroll_month_closure')]
#[ORM\UniqueConstraint(name: 'uniq_payroll_month_closure_month', columns: ['dossier_id', 'month'])]
class PayrollMonthClosure implements DossierOwned
{
    use DossierOwnedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The first day of the month closed. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $month;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $closedOn;

    public function __construct(Dossier $dossier, \DateTimeImmutable $month, \DateTimeImmutable $closedOn)
    {
        $this->dossier = $dossier;
        $this->month = $month;
        $this->closedOn = $closedOn;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMonth(): \DateTimeImmutable
    {
        return $this->month;
    }

    public function getClosedOn(): \DateTimeImmutable
    {
        return $this->closedOn;
    }
}
