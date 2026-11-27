<?php

declare(strict_types=1);

namespace App\Twig\Components\Px;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * A square button with an icon and no visible text (design-ui.md § 4.4). Its `label` is required:
 * it is the button's accessible name and its tooltip, and the component refuses to render without.
 */
#[AsTwigComponent('Px:IconButton')]
final class IconButton
{
    public string $icon;
    public string $label;
    public ?string $href = null;
    public string $type = 'button';
    public ?string $size = null;
    public ?string $shortcut = null;
    public ?bool $pressed = null;
    public bool $disabled = false;
    public bool $dot = false;
    public ?string $dotLabel = null;

    public function mount(string $icon, string $label, ?string $size = null): void
    {
        if ('' === trim($label)) {
            throw new \InvalidArgumentException('Px:IconButton needs a label: it is the only name the button has.');
        }
        if (null !== $size && 'sm' !== $size) {
            throw new \InvalidArgumentException(\sprintf('Px:IconButton has no "%s" size; only "sm".', $size));
        }
        $this->icon = $icon;
        $this->label = $label;
        $this->size = $size;
    }

    /** The accessible name, with the unread dot said in words when it shows. */
    public function accessibleName(): string
    {
        if ($this->dot && null !== $this->dotLabel) {
            return $this->label.', '.$this->dotLabel;
        }

        return $this->label;
    }
}
