<?php

declare(strict_types=1);

namespace App\Twig\Components\Px;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Initials in a disc (design-ui.md § 4.15): no photo, no colour drawn from the name. The connected
 * person (`me`) is on the action blue. Hidden from screen readers when the name is written beside
 * (`decorative`, true by default); otherwise the disc carries the name.
 */
#[AsTwigComponent('Px:Avatar')]
final class Avatar
{
    public const array SIZES = ['sm', 'lg'];

    public string $name;
    public bool $me = false;
    public ?string $size = null;
    public bool $decorative = true;

    public function mount(string $name, ?string $size = null): void
    {
        if (null !== $size && !\in_array($size, self::SIZES, true)) {
            throw new \InvalidArgumentException(\sprintf('Px:Avatar has no "%s" size; use one of: %s.', $size, implode(', ', self::SIZES)));
        }
        $this->name = $name;
        $this->size = $size;
    }

    public function initials(): string
    {
        return self::initialsOf($this->name);
    }

    /**
     * First letter of the first word and of the last one, in capitals: « Manon Chevalier » → MC,
     * « Jean-Pierre de la Tour » → JT, « Théo » → T. Accents are kept (« Élodie Roux » → ÉR).
     */
    public static function initialsOf(string $name): string
    {
        $words = preg_split('/[\s]+/u', trim($name), -1, \PREG_SPLIT_NO_EMPTY);
        if (false === $words || [] === $words) {
            return '';
        }
        $first = mb_substr($words[0], 0, 1);
        $last = \count($words) > 1 ? mb_substr($words[\count($words) - 1], 0, 1) : '';

        return mb_strtoupper($first.$last);
    }

    public function classes(): string
    {
        $classes = ['px-avatar'];
        if ($this->me) {
            $classes[] = 'px-avatar--me';
        }
        if (null !== $this->size) {
            $classes[] = 'px-avatar--'.$this->size;
        }

        return implode(' ', $classes);
    }
}
