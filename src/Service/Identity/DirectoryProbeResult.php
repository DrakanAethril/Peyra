<?php

declare(strict_types=1);

namespace App\Service\Identity;

/** The outcome of DirectoryProbe::probe(), with the directory's own message when it failed. */
final readonly class DirectoryProbeResult
{
    public function __construct(
        public DirectoryProbeOutcome $outcome,
        public ?string $detail = null,
    ) {
    }
}
