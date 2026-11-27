<?php

declare(strict_types=1);

namespace App\Service\Shared\Storage;

/**
 * Why a file is refused, as a sentence the screen prints under its field (design-ux.md § 6.1: what
 * happened and what to do, nothing technical).
 */
final class UploadRefusal extends \RuntimeException
{
}
