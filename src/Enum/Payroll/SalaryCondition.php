<?php

declare(strict_types=1);

namespace App\Enum\Payroll;

/** Whether an item applies by the bracket the salary reaches: always, or only above the ceiling (the CET of Agirc-Arrco). */
enum SalaryCondition: string
{
    case Always = 'always';
    case AboveCeiling = 'above_ceiling';

    public function label(): string
    {
        return match ($this) {
            self::Always => 'Quel que soit le salaire',
            self::AboveCeiling => 'Salaire au-dessus du plafond',
        };
    }
}
