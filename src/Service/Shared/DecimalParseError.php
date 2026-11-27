<?php

declare(strict_types=1);

namespace App\Service\Shared;

/** A number that could not be read; its message is the sentence the screen prints under the field. */
final class DecimalParseError extends \InvalidArgumentException
{
}
