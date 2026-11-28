<?php

declare(strict_types=1);

namespace App\Service\Dossier\Distribution;

/** A distribution's gesture that cannot be made; the message is the sentence shown. */
final class DistributionRefused extends \RuntimeException
{
}
