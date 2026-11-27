<?php

declare(strict_types=1);

namespace App\Twig\Components\Px;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * A KPI card (design-ui.md § 4.10). `state` colours the value for a check only: `ok` for a check
 * passed (nil gap, all done), `alert` for a non-nil gap - never to say « positive » or « negative ».
 * With an `href` the card is a link to the list it counts.
 */
#[AsTwigComponent('Px:Kpi')]
final class Kpi
{
    public string $label;
    public string $value;
    public ?string $note = null;
    public ?string $state = null;
    public ?string $href = null;

    public function mount(string $label, string $value, ?string $state = null): void
    {
        if (null !== $state && !\in_array($state, ['ok', 'alert'], true)) {
            throw new \InvalidArgumentException(\sprintf('Px:Kpi state is "ok" or "alert", not "%s".', $state));
        }
        $this->label = $label;
        $this->value = $value;
        $this->state = $state;
    }
}
