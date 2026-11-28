<?php

declare(strict_types=1);

namespace App\Service\Payroll\Payslip;

use App\Enum\Payroll\ExitReason;
use App\Enum\Payroll\PayrollBase;
use App\Service\Payroll\Plan\PayrollItemSpec;

/**
 * What the « Éléments du mois » card asks more on the last payslip of a contract (step 4.4c), pure:
 * when the exit's last day falls in the month paid and the plan has the rubrique that pays it - the
 * paid leave acquired and not taken (base « indemnité compensatrice »), the contract's gross pay of the
 * months before for the end of a fixed-term contract at its term (base « brut du CDD »), the reference
 * salary of a dismissal's or a conventional termination's indemnity, and for the latter the amount
 * agreed. Anything else stays out of the card and out of the engine's input.
 */
final readonly class LastPayslipEntries
{
    public function __construct(
        public bool $compensatoryLeave = false,
        public bool $fixedTermGross = false,
        public bool $severance = false,
        public bool $agreed = false,
    ) {
    }

    public static function none(): self
    {
        return new self();
    }

    /** @param list<PayrollItemSpec> $plan */
    public static function of(?ExitReason $reason, ?\DateTimeImmutable $exitOn, \DateTimeImmutable $month, array $plan): self
    {
        if (null === $reason || null === $exitOn || $exitOn < $month || $exitOn > PayslipBook::payDay($month)) {
            return self::none();
        }
        $bases = array_map(static fn (PayrollItemSpec $spec): PayrollBase => $spec->base, $plan);
        $severance = match ($reason) {
            ExitReason::PersonalDismissal, ExitReason::EconomicDismissal => \in_array(PayrollBase::Dismissal, $bases, true),
            ExitReason::ConventionalTermination => \in_array(PayrollBase::ConventionalBreak, $bases, true),
            default => false,
        };

        return new self(
            \in_array(PayrollBase::LeaveCompensation, $bases, true),
            ExitReason::FixedTermEnd === $reason && \in_array(PayrollBase::FixedTermEnd, $bases, true),
            $severance,
            $severance && ExitReason::ConventionalTermination === $reason,
        );
    }

    public function any(): bool
    {
        return $this->compensatoryLeave || $this->fixedTermGross || $this->severance;
    }
}
