<?php

declare(strict_types=1);

namespace App\Service\Dossier;

use App\Entity\Dossier\Dossier;
use App\Service\Shared\LivePublisher;

/**
 * What a dossier's open tabs hear (design-ux.md § 6.5): one private topic per dossier, which the
 * shell subscribes to (`dossier_live_controller.js`). For now its restore points: a point ready or
 * failed (`points`), a return starting (`restoring`: every tab goes to the waiting page) and
 * ending (`restored`). Publishing is a comfort: every screen reads right when reloaded.
 */
final readonly class DossierLive
{
    public const string POINTS = 'points';
    public const string RESTORING = 'restoring';
    public const string RESTORED = 'restored';

    public function __construct(private LivePublisher $publisher)
    {
    }

    public static function topic(int $dossierId): string
    {
        return 'peyra/dossier/'.$dossierId;
    }

    public function announce(Dossier $dossier, string $event): void
    {
        $id = $dossier->getId() ?? throw new \LogicException('A saved dossier is announced.');
        $this->publisher->publish(self::topic($id), ['event' => $event]);
    }
}
