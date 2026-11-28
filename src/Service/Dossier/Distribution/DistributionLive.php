<?php

declare(strict_types=1);

namespace App\Service\Dossier\Distribution;

use App\Entity\Dossier\Distribution;
use App\Service\Shared\LivePublisher;

/**
 * What a distribution's progress page hears (design-ux.md § 4.2, step 3: « mise à jour en
 * direct »): one private topic per distribution, a `progress` message after each copy made or
 * failed. A comfort: the page also asks again while copies are pending.
 */
final readonly class DistributionLive
{
    public const string PROGRESS = 'progress';

    public function __construct(private LivePublisher $publisher)
    {
    }

    public static function topic(int $distributionId): string
    {
        return 'peyra/distribution/'.$distributionId;
    }

    public function announce(Distribution $distribution): void
    {
        $id = $distribution->getId() ?? throw new \LogicException('A saved distribution is announced.');
        $this->publisher->publish(self::topic($id), ['event' => self::PROGRESS]);
    }
}
