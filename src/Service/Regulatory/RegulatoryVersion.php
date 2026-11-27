<?php

declare(strict_types=1);

namespace App\Service\Regulatory;

use App\Entity\Regulatory\RegulatoryValue;

/**
 * The value of one effective date of a key: the row in force (the last one written for that
 * date), the rows it corrected, and the last day it applies, the eve of the next date.
 */
final readonly class RegulatoryVersion
{
    /** @param list<RegulatoryValue> $corrected the earlier rows of the same date, newest first */
    public function __construct(public RegulatoryValue $value, public array $corrected, public ?\DateTimeImmutable $until)
    {
    }

    public function appliesOn(\DateTimeImmutable $day): bool
    {
        return $this->value->getEffectiveFrom() <= $day && (null === $this->until || $day <= $this->until);
    }

    /** Whether it applies on at least one day of that calendar year. */
    public function appliesDuring(int $year): bool
    {
        return (int) $this->value->getEffectiveFrom()->format('Y') <= $year && (null === $this->until || (int) $this->until->format('Y') >= $year);
    }
}
