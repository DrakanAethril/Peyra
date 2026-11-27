<?php

declare(strict_types=1);

namespace App\Twig\Components\Px;

use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The amount field (design-ui.md § 4.5): a visible text field that is only a display, in mono,
 * right-aligned, and a hidden field `name` that carries the whole cents the server reads. Typing
 * accepts digits, a comma (a point becomes one), a leading minus and + − * / evaluated on leaving
 * the field (« 2760-138 » → « 2 622,00 »); amount_input_controller.js does it. `grid` gives the
 * 112 px of an entry grid, 140 px otherwise; `unit` is a grey suffix: €, % or h.
 *
 *     <twig:Px:Field id="amount" label="Montant TTC">
 *         <twig:Px:AmountInput id="amount" name="amount" :value="document.total" unit="€"/>
 *     </twig:Px:Field>
 */
#[AsTwigComponent('Px:AmountInput')]
final class AmountInput
{
    private const array UNITS = ['€', '%', 'h'];

    public string $id;
    public ?string $name = null;
    public ?int $cents = null;
    public ?string $unit = null;
    public bool $grid = false;
    public bool $required = false;
    public bool|string|null $hint = null;
    public bool|string|null $error = null;

    public function mount(string $id, Money|int|null $value = null, ?string $unit = null): void
    {
        if (null !== $unit && !\in_array($unit, self::UNITS, true)) {
            throw new \InvalidArgumentException(\sprintf('Px:AmountInput unit is one of %s, not "%s".', implode(', ', self::UNITS), $unit));
        }
        $this->id = $id;
        $this->unit = $unit;
        $this->cents = $value instanceof Money ? $value->cents : $value;
    }

    public function display(): string
    {
        return null === $this->cents ? '' : FrenchFormat::amount(Money::cents($this->cents));
    }

    public function describedBy(): string
    {
        return implode(' ', array_filter([
            $this->hint ? $this->id.'_hint' : null,
            $this->error ? $this->id.'_error' : null,
        ]));
    }
}
