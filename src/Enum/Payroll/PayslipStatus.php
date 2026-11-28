<?php

declare(strict_types=1);

namespace App\Enum\Payroll;

use App\Enum\Shared\BadgeTone;
use App\Enum\Shared\HasBadge;

/**
 * Where an employee's payslip of the month stands (steps 4.4a and 4.4b; design-ui.md § 4.9): not
 * calculated yet; calculated from the elements entered, or with lines changed by hand, and waiting to
 * be checked; validated, frozen. Green is for the validated state alone.
 */
enum PayslipStatus: string implements HasBadge
{
    case ToDo = 'todo';
    case Calculated = 'calculated';
    case Modified = 'modified';
    case Validated = 'validated';

    public function label(): string
    {
        return match ($this) {
            self::ToDo => 'À faire',
            self::Calculated => 'Calculé, à valider',
            self::Modified => 'Modifié, à valider',
            self::Validated => 'Validé',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::ToDo => BadgeTone::Neutral,
            self::Calculated => BadgeTone::Warning,
            self::Modified => BadgeTone::Info,
            self::Validated => BadgeTone::Success,
        };
    }

    /** Calculated, by the engine alone or with lines changed by hand, and not validated yet. */
    public function isToValidate(): bool
    {
        return self::Calculated === $this || self::Modified === $this;
    }
}
