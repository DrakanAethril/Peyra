<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Attribute\AsTwigFunction;

/**
 * Twig helpers of the design system: px_key() prints a combination's French key label.
 */
final class PxExtension
{
    #[AsTwigFunction('px_key')]
    public static function key(string $combo): string
    {
        return Shortcut::label($combo);
    }
}
