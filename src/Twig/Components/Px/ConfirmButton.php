<?php

declare(strict_types=1);

namespace App\Twig\Components\Px;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The two-step button (design-ui.md § 4.4, design-ux.md § 6.2): first click arms it (« Supprimer
 * le brouillon » → « Confirmer la suppression », solid red, « Annuler » on its left), the second
 * does it. Never confirm(), never a dialog. A submit button by default: put it in the form whose
 * gesture it confirms, or name that form with `form`.
 */
#[AsTwigComponent('Px:ConfirmButton')]
final class ConfirmButton
{
    public string $label;
    public string $confirmLabel;
    public string $variant = 'danger';
    public ?string $size = null;
    public ?string $icon = null;
    public string $type = 'submit';
    public ?string $form = null;

    public function mount(string $label, string $confirmLabel, string $variant = 'danger', ?string $size = null): void
    {
        if (!\in_array($variant, ['danger', 'secondary'], true)) {
            throw new \InvalidArgumentException(\sprintf('Px:ConfirmButton is "danger" or "secondary", not "%s".', $variant));
        }
        if (null !== $size && !\in_array($size, Button::SIZES, true)) {
            throw new \InvalidArgumentException(\sprintf('Px:ConfirmButton has no "%s" size.', $size));
        }
        if ('' === trim($label) || '' === trim($confirmLabel)) {
            throw new \InvalidArgumentException('Px:ConfirmButton needs both labels: what the gesture is, and how it is confirmed.');
        }
        $this->label = $label;
        $this->confirmLabel = $confirmLabel;
        $this->variant = $variant;
        $this->size = $size;
    }

    public function classes(): string
    {
        $classes = ['px-btn', 'px-confirm__button'];
        if ('danger' === $this->variant) {
            $classes[] = 'px-btn--danger';
        }
        if (null !== $this->size) {
            $classes[] = 'px-btn--'.$this->size;
        }

        return implode(' ', $classes);
    }
}
