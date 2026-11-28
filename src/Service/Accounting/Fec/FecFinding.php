<?php

declare(strict_types=1);

namespace App\Service\Accounting\Fec;

/** A fault of a FEC: the line of the file (null for the name or the whole file), the zone, the sentence. */
final readonly class FecFinding
{
    public function __construct(
        public ?int $line,
        public ?string $zone,
        public string $message,
    ) {
    }
}
