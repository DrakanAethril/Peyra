<?php

declare(strict_types=1);

namespace App\Twig\Components\Px;

use App\Twig\Shortcut;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * A .px-btn (design-ui.md § 4.4): an <a> when given an `href`, a <button> otherwise (`type`
 * defaults to "button", so a button dropped into a form never submits it by accident).
 *
 *   <twig:Px:Button variant="primary" type="submit" icon="save" shortcut="F10">Enregistrer</twig:Px:Button>
 *
 * `shortcut` is a canonical combination ("F10", "Ctrl+Enter"): the button answers it (the global
 * shortcuts controller clicks it) and shows its key on the right. A disabled primary button says
 * why, in `disabledReason`, as a title and a described-by text.
 */
#[AsTwigComponent('Px:Button')]
final class Button
{
    public const array VARIANTS = ['secondary', 'primary', 'quiet', 'danger'];
    public const array SIZES = ['sm', 'lg'];

    public string $variant = 'secondary';
    public ?string $size = null;
    public ?string $href = null;
    public string $type = 'button';
    public ?string $icon = null;
    public ?string $shortcut = null;
    public ?bool $pressed = null;
    public bool $disabled = false;
    public bool $busy = false;
    public bool $block = false;
    public ?string $disabledReason = null;
    public ?string $id = null;

    public function mount(string $variant = 'secondary', ?string $size = null): void
    {
        if (!\in_array($variant, self::VARIANTS, true)) {
            throw new \InvalidArgumentException(\sprintf('Px:Button has no "%s" variant; use one of: %s.', $variant, implode(', ', self::VARIANTS)));
        }
        if (null !== $size && !\in_array($size, self::SIZES, true)) {
            throw new \InvalidArgumentException(\sprintf('Px:Button has no "%s" size; use one of: %s.', $size, implode(', ', self::SIZES)));
        }
        $this->variant = $variant;
        $this->size = $size;
    }

    public function classes(): string
    {
        $classes = ['px-btn'];
        if ('secondary' !== $this->variant) {
            $classes[] = 'px-btn--'.$this->variant;
        }
        if (null !== $this->size) {
            $classes[] = 'px-btn--'.$this->size;
        }
        if ($this->block) {
            $classes[] = 'px-btn--block';
        }

        return implode(' ', $classes);
    }

    /** The key label shown in the button, the first combination of `shortcut`. */
    public function keyLabel(): ?string
    {
        if (null === $this->shortcut || '' === trim($this->shortcut)) {
            return null;
        }

        return Shortcut::label(explode(' ', trim($this->shortcut))[0]);
    }

    /** The id of the text that explains a disabled button, when there is one to explain. */
    public function reasonId(): ?string
    {
        if (!$this->disabled || null === $this->disabledReason) {
            return null;
        }

        return ($this->id ?? 'px-btn-'.substr(hash('xxh3', $this->disabledReason), 0, 8)).'-reason';
    }
}
