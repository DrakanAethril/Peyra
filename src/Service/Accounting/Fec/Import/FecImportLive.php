<?php

declare(strict_types=1);

namespace App\Service\Accounting\Fec\Import;

use App\Service\Shared\LivePublisher;

/**
 * What a FEC import's progress page hears (step 1.10b): one private topic per import, a
 * `progress` message after each pass. A comfort: the page also asks again while passes are left.
 */
final readonly class FecImportLive
{
    public const string PROGRESS = 'progress';

    public function __construct(private LivePublisher $publisher)
    {
    }

    public static function topic(int $jobId): string
    {
        return 'peyra/fec-import/'.$jobId;
    }

    public function announce(int $jobId): void
    {
        $this->publisher->publish(self::topic($jobId), ['event' => self::PROGRESS]);
    }
}
