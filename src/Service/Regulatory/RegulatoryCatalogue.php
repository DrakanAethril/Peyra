<?php

declare(strict_types=1);

namespace App\Service\Regulatory;

use App\Repository\Regulatory\RegulatoryValueRepository;

/** The regulatory values as the screens read them: one history per key. */
final readonly class RegulatoryCatalogue
{
    public function __construct(private RegulatoryValueRepository $values)
    {
    }

    /** @return array<string, RegulatoryHistory> by key, in key order */
    public function histories(): array
    {
        return RegulatoryHistory::byKey($this->values->findAllInReadingOrder());
    }

    public function history(string $key): ?RegulatoryHistory
    {
        $rows = $this->values->findForKey($key);

        return [] === $rows ? null : RegulatoryHistory::of($rows);
    }
}
