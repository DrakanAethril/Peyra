<?php

declare(strict_types=1);

namespace App\Twig\Components\Px;

use App\Twig\Shortcut;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * A key (design-ui.md § 4.15): one <kbd> per combination, labelled in French.
 *
 *   <twig:Px:Kbd combo="Ctrl+Enter"/>  →  <kbd class="px-kbd">Ctrl ↵</kbd>
 */
#[AsTwigComponent('Px:Kbd')]
final class Kbd
{
    public string $label;

    public function mount(string $combo): void
    {
        $this->label = Shortcut::label($combo);
    }
}
