<?php

declare(strict_types=1);

namespace App\Enum\Regulatory;

/** The family of a regulatory value, the first segment of its key (« social.smic_hourly »). */
enum RegulatoryDomain: string
{
    case Social = 'social';
    case Fiscal = 'fiscal';

    public static function ofKey(string $key): ?self
    {
        return self::tryFrom(explode('.', $key, 2)[0]);
    }

    public function label(): string
    {
        return match ($this) {
            self::Social => 'Social',
            self::Fiscal => 'Fiscal',
        };
    }
}
