<?php

declare(strict_types=1);

namespace App\Service\Regulatory;

use App\Repository\Regulatory\RegulatoryValueRepository;

/**
 * The one reading of the regulatory values by a calculation (README of the plan, § 8): the values
 * in force on a day - the dossier's date, never the server's. No rate is written in the code.
 */
final readonly class RegulatoryReader
{
    public function __construct(private RegulatoryValueRepository $values)
    {
    }

    public function at(\DateTimeImmutable $day): RegulatorySnapshot
    {
        $inForce = [];
        // In reading order: for each key, the last row taking effect by that day is the one in force.
        foreach ($this->values->findEffectiveOn($day) as $row) {
            $inForce[$row->getKey()] = $row;
        }

        return new RegulatorySnapshot($day, $inForce);
    }
}
