<?php

declare(strict_types=1);

namespace App\Service\Accounting\Fec\Import;

/** An account a FEC names, as the template's plan will hold it: collective when its lines carry auxiliary codes, lettrable by default or when its lines are lettered. */
final readonly class FecImportAccount
{
    public function __construct(
        public string $number,
        public string $label,
        public bool $collective,
        public bool $lettrable,
    ) {
    }
}
