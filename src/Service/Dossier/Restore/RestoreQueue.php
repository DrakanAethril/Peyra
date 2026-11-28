<?php

declare(strict_types=1);

namespace App\Service\Dossier\Restore;

use App\Entity\Dossier\DossierRestoration;
use App\Entity\Dossier\RestorePoint;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The restore work a request asked for, done once its answer is sent (App\EventListener\
 * RestoreWorkListener, on kernel.terminate): the person gets their page at once, the copy follows
 * (design-ux.md § 6.4: « la demande rend la main tout de suite »). Whatever a stopped worker left
 * behind, app:restore-points:advance finishes.
 */
final class RestoreQueue implements ResetInterface
{
    /** @var list<int> */
    private array $points = [];

    /** @var list<int> */
    private array $restorations = [];

    public function point(RestorePoint $point): void
    {
        $id = $point->getId() ?? throw new \LogicException('A saved point is queued.');
        if (!\in_array($id, $this->points, true)) {
            $this->points[] = $id;
        }
    }

    public function restoration(DossierRestoration $restoration): void
    {
        $id = $restoration->getId() ?? throw new \LogicException('A saved return is queued.');
        if (!\in_array($id, $this->restorations, true)) {
            $this->restorations[] = $id;
        }
    }

    /** @return array{points: list<int>, restorations: list<int>} what was asked, emptied */
    public function take(): array
    {
        $work = ['points' => $this->points, 'restorations' => $this->restorations];
        $this->reset();

        return $work;
    }

    public function reset(): void
    {
        $this->points = [];
        $this->restorations = [];
    }
}
