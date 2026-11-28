<?php

declare(strict_types=1);

namespace App\Enum\Payroll;

/** An employee's sex as the civil status and the NIR's first digit write it (1 a man, 2 a woman). */
enum Sex: string
{
    case Male = 'M';
    case Female = 'F';

    public function label(): string
    {
        return match ($this) {
            self::Male => 'Homme',
            self::Female => 'Femme',
        };
    }

    /** The first digit of a NIR. */
    public function nirDigit(): string
    {
        return self::Male === $this ? '1' : '2';
    }
}
