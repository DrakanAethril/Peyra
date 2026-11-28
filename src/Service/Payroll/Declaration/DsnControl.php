<?php

declare(strict_types=1);

namespace App\Service\Payroll\Declaration;

/** A consistency check of the DSN's preparatory state (step 4.6): what is checked, whether it holds, and what was found. */
final readonly class DsnControl
{
    public function __construct(public string $label, public bool $holds, public string $finding)
    {
    }
}
