<?php

declare(strict_types=1);

namespace App\Twig\Components\Px;

use App\Enum\Shared\BadgeTone;
use App\Enum\Shared\HasBadge;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * A state pill (design-ui.md § 4.9). Either `:of="value"` reads text and tone from a HasBadge
 * enum, or `tone` (a BadgeTone value) plus the content. A pill always has text; an abbreviated one
 * (« A », « 0 ») gives its full wording in `full`, as a title and a screen-reader text.
 * `dot` (true by default) marks a state; a nature (« Factur-X ») goes without.
 */
#[AsTwigComponent('Px:Badge')]
final class Badge
{
    public BadgeTone $tone = BadgeTone::Neutral;
    public ?string $text = null;
    public bool $dot = true;
    public ?string $full = null;

    public function mount(?HasBadge $of = null, BadgeTone|string|null $tone = null, ?string $label = null): void
    {
        if (null !== $of) {
            $this->tone = $of->badgeTone();
            $this->text = $of->label();

            return;
        }
        if (null === $tone) {
            throw new \InvalidArgumentException('Px:Badge needs either :of (a HasBadge value) or a tone.');
        }
        $this->tone = $tone instanceof BadgeTone ? $tone : (BadgeTone::tryFrom($tone) ?? throw new \InvalidArgumentException(\sprintf('Px:Badge has no "%s" tone; use one of: %s.', $tone, implode(', ', array_column(BadgeTone::cases(), 'value')))));
        $this->text = $label;
    }

    public function classes(): string
    {
        return 'px-badge px-badge--'.$this->tone->value.($this->dot ? ' px-badge--dot' : '');
    }
}
