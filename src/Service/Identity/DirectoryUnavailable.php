<?php

declare(strict_types=1);

namespace App\Service\Identity;

/** The directory could not be reached, or refused the service account. */
final class DirectoryUnavailable extends \RuntimeException
{
}
