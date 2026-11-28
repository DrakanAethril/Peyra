<?php

declare(strict_types=1);

namespace App\Enum\Payroll;

/**
 * Full time works the establishment's collective time (« 151,67 h »); part time works fewer hours,
 * written in the contract.
 */
enum WorkingTime: string
{
    case FullTime = 'full_time';
    case PartTime = 'part_time';

    public function label(): string
    {
        return match ($this) {
            self::FullTime => 'Temps plein',
            self::PartTime => 'Temps partiel',
        };
    }
}
