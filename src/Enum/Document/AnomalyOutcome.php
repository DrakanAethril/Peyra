<?php

declare(strict_types=1);

namespace App\Enum\Document;

/**
 * What the student does with a pièce once an anomaly is reported (design-ux.md § 4.3): reject it
 * (« Rejetées », with the comment as its reason), or post it all the same, the reserve kept.
 */
enum AnomalyOutcome: string
{
    case Reject = 'reject';
    case PostWithReserve = 'post_with_reserve';

    public function label(): string
    {
        return match ($this) {
            self::Reject => 'Rejeter la pièce',
            self::PostWithReserve => 'Comptabiliser avec réserve',
        };
    }
}
