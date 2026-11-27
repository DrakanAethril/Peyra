<?php

declare(strict_types=1);

namespace App\Twig\Components\Px;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * A chip: a context, not a state (design-ui.md § 4.2) - the student's profile, « Espace
 * enseignant ». States are pills (Px:Badge).
 */
#[AsTwigComponent('Px:Chip')]
final class Chip
{
    public const array TONES = ['neutral', 'role', 'info'];

    public string $tone = 'neutral';
    public ?string $icon = null;

    public function mount(string $tone = 'neutral'): void
    {
        if (!\in_array($tone, self::TONES, true)) {
            throw new \InvalidArgumentException(\sprintf('Px:Chip has no "%s" tone; use one of: %s.', $tone, implode(', ', self::TONES)));
        }
        $this->tone = $tone;
    }

    public function classes(): string
    {
        return 'neutral' === $this->tone ? 'px-chip' : 'px-chip px-chip--'.$this->tone;
    }
}
