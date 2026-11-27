<?php

declare(strict_types=1);

namespace App\Service\Identity;

/** One line of YearPreparation: passed or not, its sentence, the administration tab that settles it (years, classes, mapping). */
final readonly class PreparationCheck
{
    public function __construct(public bool $passed, public string $text, public string $tab)
    {
    }
}
