<?php

declare(strict_types=1);

namespace App\Service\Shared\Referential;

use App\Enum\Shared\BtsProcess;

/** A process (P1 to P7) and its activities, in the text's order. */
final readonly class ReferentialProcess
{
    /** @param list<ReferentialActivity> $activities */
    public function __construct(
        public BtsProcess $process,
        public string $title,
        public array $activities,
        public bool $verified,
        public ?string $note,
    ) {
    }
}
