<?php

declare(strict_types=1);

namespace App\Service\Regulatory;

/**
 * A calculation asked for a regulatory value the millésimes do not hold at that date, or for a
 * number where the value holds a text. Never answered with a default: a rate made up is worse
 * than a calculation that stops.
 */
final class MissingRegulatoryValue extends \RuntimeException
{
}
