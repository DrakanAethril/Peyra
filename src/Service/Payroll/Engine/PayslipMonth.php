<?php

declare(strict_types=1);

namespace App\Service\Payroll\Engine;

use App\Enum\Payroll\PayslipUnit;
use App\Service\Shared\FrenchFormat;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The calendar of a payslip's month (lot 4, step 4.3d), pure: the month of the payslip's day, the days
 * the contract covers in it (hired or leaving during the month), and the monthly ceiling those days
 * carry.
 *
 * - **Salary of an incomplete month**: the days outside the contract come off at the calendar day's
 *   rate, the salary divided by the days of the month (DECISIONS 4.3d).
 * - **Ceiling** (R242-2 CSS; DSN instruction sheet 2713, net-entreprises, `social.ceiling_proration`):
 *   the monthly ceiling times the calendar days of the employment period, less the whole days of absence
 *   that gave rise to no payment at all, over the calendar days of the month, rounded to the cent. A day
 *   partly worked, paid leave or a sick day the employer pays any part of keeps its day.
 */
final readonly class PayslipMonth
{
    public const string PRORATION_KEY = 'social.ceiling_proration';

    public \DateTimeImmutable $first;
    public \DateTimeImmutable $last;
    public int $days;
    public \DateTimeImmutable $from;
    public \DateTimeImmutable $to;
    public int $presentDays;
    public int $unpaidDays;

    public function __construct(\DateTimeImmutable $day, ?\DateTimeImmutable $hiredOn = null, ?\DateTimeImmutable $leavesOn = null, int $unpaidDays = 0)
    {
        $this->first = $day->setTime(0, 0)->modify('first day of this month');
        $this->last = $day->setTime(0, 0)->modify('last day of this month');
        $this->days = (int) $this->last->format('j');
        $hired = $hiredOn?->setTime(0, 0);
        $leaves = $leavesOn?->setTime(0, 0);
        $this->from = null !== $hired && $hired > $this->first ? $hired : $this->first;
        $this->to = null !== $leaves && $leaves < $this->last ? $leaves : $this->last;
        if ($this->to < $this->from) {
            throw new \InvalidArgumentException(\sprintf('The contract covers no day of %s: there is no payslip.', $this->first->format('Y-m')));
        }
        $this->presentDays = (int) $this->from->diff($this->to)->days + 1;
        if ($unpaidDays < 0 || $unpaidDays > $this->presentDays) {
            throw new \InvalidArgumentException('The whole days of unpaid absence are counted within the days of the contract.');
        }
        $this->unpaidDays = $unpaidDays;
    }

    public static function of(PayslipInput $input): self
    {
        return new self($input->regulatory->day, $input->employee->hiredOn, $input->employee->leavesOn, $input->variables->unpaidDays);
    }

    /** The calendar days of the month outside the contract (before the hiring, after the last day). */
    public function outsideDays(): int
    {
        return $this->days - $this->presentDays;
    }

    public function isComplete(): bool
    {
        return 0 === $this->outsideDays();
    }

    /** The days the ceiling is counted on: the employment period's, less the whole days paid nothing. */
    public function ceilingDays(): int
    {
        return $this->presentDays - $this->unpaidDays;
    }

    /** The ceiling of the month: the monthly ceiling, prorated when it does not count every day of the month. */
    public function ceiling(BigDecimal $monthly): BigDecimal
    {
        if ($this->ceilingDays() === $this->days) {
            return $monthly;
        }

        return $monthly->multipliedBy($this->ceilingDays())->dividedBy($this->days, 2, RoundingMode::HalfUp);
    }

    /** How the ceiling of the month was found, for the lines that read it. */
    public function ceilingExplanation(BigDecimal $monthly): string
    {
        $euros = PayslipUnit::Euros;
        if ($this->ceilingDays() === $this->days) {
            return 'plafond mensuel '.$euros->format($monthly);
        }
        $why = [];
        if (!$this->isComplete()) {
            $why[] = \sprintf('contrat du %s au %s', FrenchFormat::date($this->from), FrenchFormat::date($this->to));
        }
        if ($this->unpaidDays > 0) {
            $why[] = \sprintf('%d jour%s entier%s d’absence sans aucun versement', $this->unpaidDays, $this->unpaidDays > 1 ? 's' : '', $this->unpaidDays > 1 ? 's' : '');
        }

        return \sprintf('plafond du mois proratisé (%s) : %s × %d ÷ %d jours = %s', implode(', ', $why), $euros->format($monthly), $this->ceilingDays(), $this->days, $euros->format($this->ceiling($monthly)));
    }
}
