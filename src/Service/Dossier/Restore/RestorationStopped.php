<?php

declare(strict_types=1);

namespace App\Service\Dossier\Restore;

/** A return to a restore point that could not be done, with the French sentence the dossier's people read. */
final class RestorationStopped extends \RuntimeException
{
    public function __construct(string $sentence, ?\Throwable $previous = null)
    {
        parent::__construct($sentence, 0, $previous);
    }
}
