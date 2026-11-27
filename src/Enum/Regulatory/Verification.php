<?php

declare(strict_types=1);

namespace App\Enum\Regulatory;

use App\Enum\Shared\BadgeTone;
use App\Enum\Shared\HasBadge;

/** Whether a regulatory value was read on an official source (`verified` of the millésime files). */
enum Verification: string implements HasBadge
{
    case Verified = 'verified';
    case Unverified = 'unverified';

    public static function of(bool $verified): self
    {
        return $verified ? self::Verified : self::Unverified;
    }

    public function label(): string
    {
        return match ($this) {
            self::Verified => 'Vérifiée',
            self::Unverified => 'À vérifier',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Verified => BadgeTone::Success,
            self::Unverified => BadgeTone::Warning,
        };
    }
}
