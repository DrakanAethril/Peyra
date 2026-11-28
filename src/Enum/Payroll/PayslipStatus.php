<?php

declare(strict_types=1);

namespace App\Enum\Payroll;

use App\Enum\Shared\BadgeTone;
use App\Enum\Shared\HasBadge;

/** Where an employee's payslip of the month stands (step 4.4a; design-ui.md § 4.9): not calculated yet, or calculated from the elements entered and waiting to be checked. */
enum PayslipStatus: string implements HasBadge
{
    case ToDo = 'todo';
    case Calculated = 'calculated';

    public function label(): string
    {
        return match ($this) {
            self::ToDo => 'À faire',
            self::Calculated => 'Calculé, à valider',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return self::Calculated === $this ? BadgeTone::Warning : BadgeTone::Neutral;
    }
}
