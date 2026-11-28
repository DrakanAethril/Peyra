<?php

declare(strict_types=1);

namespace App\Service\Payroll\Engine;

use App\Service\Regulatory\RegulatorySnapshot;

/**
 * The regulatory value a payslip line read (lot 4, step 4.3): its key and path, its label, the
 * date it took effect, the official page it was read on and whether it was verified there - what
 * F5 shows under a line.
 */
final readonly class PayslipSource
{
    public function __construct(
        public string $key,
        public ?string $path,
        public string $label,
        public \DateTimeImmutable $effectiveFrom,
        public ?string $url,
        public bool $verified,
    ) {
    }

    public static function of(RegulatorySnapshot $snapshot, string $key, ?string $path): self
    {
        $value = $snapshot->value($key);

        return new self($key, $path, $value->getLabel(), $value->getEffectiveFrom(), $value->getSourceUrl(), $value->isVerified());
    }
}
