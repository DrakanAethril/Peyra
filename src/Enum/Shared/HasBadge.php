<?php

declare(strict_types=1);

namespace App\Enum\Shared;

/**
 * A value drawn as a pill (<twig:Px:Badge :of="value"/>): the pairing of a state with its colour
 * is written on the enum, never as a ternary in a template.
 */
interface HasBadge
{
    /** The French label shown in the pill. */
    public function label(): string;

    public function badgeTone(): BadgeTone;
}
