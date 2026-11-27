<?php

declare(strict_types=1);

namespace App\Twig\Components\Px;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * An entry of the rail (design-ui.md § 4.1). A module the mission closes is not rendered at all -
 * there is no disabled entry. A counter says in words what it counts (`countLabel`), since folded
 * it is only an amber dot: the component refuses a count without one.
 */
#[AsTwigComponent('Px:RailItem')]
final class RailItem
{
    public string $href;
    public string $icon;
    public string $label;
    public bool $current = false;
    public ?int $count = null;
    public ?string $countLabel = null;

    /** Entries reachable by Alt 1…0; the rail's foot entries opt out. */
    public bool $numbered = true;

    public function mount(string $href, string $icon, string $label, ?int $count = null, ?string $countLabel = null): void
    {
        if (null !== $count && $count > 0 && (null === $countLabel || '' === trim($countLabel))) {
            throw new \InvalidArgumentException(\sprintf('Px:RailItem « %s » shows a count without saying what it counts (countLabel).', $label));
        }
        $this->href = $href;
        $this->icon = $icon;
        $this->label = $label;
        $this->count = null !== $count && $count > 0 ? $count : null;
        $this->countLabel = $countLabel;
    }
}
