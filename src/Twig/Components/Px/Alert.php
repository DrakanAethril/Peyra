<?php

declare(strict_types=1);

namespace App\Twig\Components\Px;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * An inline alert (design-ui.md § 4.13). The tone decides the icon and the role: `danger` is an
 * alert (announced at once), the others a status. The text stays in the ordinary ink on every tint.
 */
#[AsTwigComponent('Px:Alert')]
final class Alert
{
    private const array ICONS = [
        'info' => 'info',
        'success' => 'circle-check',
        'warning' => 'triangle-alert',
        'danger' => 'circle-alert',
    ];

    public string $tone = 'info';
    public ?string $title = null;
    public ?string $actionLabel = null;
    public ?string $actionHref = null;

    public function mount(string $tone = 'info'): void
    {
        if (!\array_key_exists($tone, self::ICONS)) {
            throw new \InvalidArgumentException(\sprintf('Px:Alert has no "%s" tone; use one of: %s.', $tone, implode(', ', array_keys(self::ICONS))));
        }
        $this->tone = $tone;
    }

    public function icon(): string
    {
        return self::ICONS[$this->tone];
    }

    public function role(): string
    {
        return 'danger' === $this->tone ? 'alert' : 'status';
    }
}
