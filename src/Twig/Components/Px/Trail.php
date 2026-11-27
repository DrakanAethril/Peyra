<?php

declare(strict_types=1);

namespace App\Twig\Components\Px;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The trail above a page title (design-ui.md § 4.3): the module, then its levels, the current page
 * last. It never starts with « Accueil » - the rail is always there - and refuses a caller who
 * passes it. Every segment is a link; the last carries aria-current="page".
 *
 *   {{ component('Px:Trail', {segments: [{label: 'Comptabilité', href: …}, {label: 'Saisie', href: …}]}) }}
 */
#[AsTwigComponent('Px:Trail')]
final class Trail
{
    /** @var list<array{label: string, href: string}> */
    public array $segments = [];

    /**
     * @param array<array-key, mixed> $segments
     */
    public function mount(array $segments): void
    {
        $clean = [];
        foreach ($segments as $segment) {
            if (!\is_array($segment) || !\is_string($segment['label'] ?? null) || !\is_string($segment['href'] ?? null)) {
                throw new \InvalidArgumentException('Px:Trail takes segments shaped {label: string, href: string}.');
            }
            $clean[] = ['label' => $segment['label'], 'href' => $segment['href']];
        }
        if ([] === $clean) {
            throw new \InvalidArgumentException('Px:Trail needs at least one segment: the module.');
        }
        if ('accueil' === mb_strtolower(trim($clean[0]['label']))) {
            throw new \InvalidArgumentException('Px:Trail never starts with « Accueil »: the rail is always there (design-ui.md § 4.3).');
        }
        $this->segments = $clean;
    }
}
