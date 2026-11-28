<?php

declare(strict_types=1);

namespace App\Service\Dossier\Distribution;

use App\Entity\Dossier\Distribution;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The distributions a request launched or relaunched, whose copies are made once its answer is
 * sent (App\EventListener\DistributionWorkListener, on kernel.terminate): the teacher gets the
 * progress page at once (design-ux.md § 6.4). What a stopped worker leaves, app:distribution:advance
 * finishes.
 */
final class DistributionQueue implements ResetInterface
{
    /** @var list<int> */
    private array $distributions = [];

    public function add(Distribution $distribution): void
    {
        $id = $distribution->getId() ?? throw new \LogicException('A saved distribution is queued.');
        if (!\in_array($id, $this->distributions, true)) {
            $this->distributions[] = $id;
        }
    }

    /** @return list<int> what was asked, emptied */
    public function take(): array
    {
        $taken = $this->distributions;
        $this->reset();

        return $taken;
    }

    public function reset(): void
    {
        $this->distributions = [];
    }
}
