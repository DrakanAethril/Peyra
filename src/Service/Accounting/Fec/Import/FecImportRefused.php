<?php

declare(strict_types=1);

namespace App\Service\Accounting\Fec\Import;

use App\Service\Accounting\Fec\FecFinding;

/** The writing of a FEC's template stopped by the doors it goes through: everything is rolled back. */
final class FecImportRefused extends \RuntimeException
{
    /** @param list<FecFinding> $findings */
    public function __construct(public readonly array $findings)
    {
        parent::__construct('The FEC is refused.');
    }
}
