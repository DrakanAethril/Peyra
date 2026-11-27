<?php

declare(strict_types=1);

namespace App\Service\Shared;

/**
 * An amount that could not be read; its message is the sentence the screen prints under the field
 * (design-ux.md § 6.1: what happened and what to do, in French).
 */
final class AmountParseError extends \InvalidArgumentException
{
}
