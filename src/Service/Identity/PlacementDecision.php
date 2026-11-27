<?php

declare(strict_types=1);

namespace App\Service\Identity;

use App\Enum\Identity\PlacementOutcome;

/** What PlacementRule decided: the class to place in, or none and why. */
final readonly class PlacementDecision
{
    private function __construct(public PlacementOutcome $outcome, public ?int $classId)
    {
    }

    public static function placed(int $classId): self
    {
        return new self(PlacementOutcome::Placed, $classId);
    }

    public static function unplaced(PlacementOutcome $outcome): self
    {
        if (PlacementOutcome::Placed === $outcome) {
            throw new \LogicException('A placed decision names its class.');
        }

        return new self($outcome, null);
    }
}
