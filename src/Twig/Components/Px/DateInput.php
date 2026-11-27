<?php

declare(strict_types=1);

namespace App\Twig\Components\Px;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The date field (design-ui.md § 4.5): « JJ/MM/AAAA » typed in mono, 112 px, a calendar button
 * inside on the right, and a hidden field `name` carrying « 2026-11-12 », the only value the server
 * reads (App\Service\Shared\DateValue). Never an `<input type="date">`, which reads the computer's
 * clock: `reference` is the dossier's date, the day abbreviations complete against (« 12 » → the
 * 12th of its month, « 1211 » → 12/11 of its year, + and − move by a day) and the calendar's
 * « Date du dossier ». date_input_controller.js does the typing and the calendar.
 */
#[AsTwigComponent('Px:DateInput')]
final class DateInput
{
    public string $id;
    public ?string $name = null;
    public ?\DateTimeInterface $value = null;
    public \DateTimeInterface $reference;
    public bool $required = false;
    public bool|string|null $hint = null;
    public bool|string|null $error = null;

    public function mount(string $id, \DateTimeInterface $reference, ?\DateTimeInterface $value = null): void
    {
        $this->id = $id;
        $this->reference = $reference;
        $this->value = $value;
    }

    public function describedBy(): string
    {
        return implode(' ', array_filter([
            $this->hint ? $this->id.'_hint' : null,
            $this->error ? $this->id.'_error' : null,
        ]));
    }
}
