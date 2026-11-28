<?php

declare(strict_types=1);

namespace App\Service\Accounting\Fec\Import;

use App\Entity\Accounting\FecImportJob;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The FEC imports a request launched or relaunched, whose passes run once its answer is sent
 * (App\EventListener\FecImportWorkListener, on kernel.terminate): the teacher gets the progress
 * page at once (design-ux.md § 6.4). What a stopped worker leaves, app:fec-import:advance finishes.
 */
final class FecImportQueue implements ResetInterface
{
    /** @var list<int> */
    private array $jobs = [];

    public function add(FecImportJob $job): void
    {
        $id = $job->getId() ?? throw new \LogicException('A saved import is queued.');
        if (!\in_array($id, $this->jobs, true)) {
            $this->jobs[] = $id;
        }
    }

    /** @return list<int> what was asked, emptied */
    public function take(): array
    {
        $taken = $this->jobs;
        $this->reset();

        return $taken;
    }

    public function reset(): void
    {
        $this->jobs = [];
    }
}
