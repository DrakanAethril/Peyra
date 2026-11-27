<?php

declare(strict_types=1);

namespace App\Twig;

/**
 * The canonical names of keyboard combinations ("Ctrl+Enter", "Alt+1", "F10") and their French
 * labels as the screens print them in a <kbd> ("Ctrl ↵", "Alt 1", "F10"). The browser side
 * (assets/shortcuts/registry.js) speaks the same names; this is the label table's server half.
 */
final class Shortcut
{
    private const array NAMES = [
        'Ctrl' => 'Ctrl',
        'Alt' => 'Alt',
        'Shift' => 'Maj',
        'Enter' => '↵',
        'Escape' => 'Échap',
        'Space' => 'Espace',
        'Delete' => 'Suppr',
        'Insert' => 'Inser',
        'Tab' => 'Tab',
        'ArrowUp' => '↑',
        'ArrowDown' => '↓',
        'ArrowLeft' => '←',
        'ArrowRight' => '→',
        'PageUp' => 'Page préc.',
        'PageDown' => 'Page suiv.',
        'Home' => 'Début',
        'End' => 'Fin',
    ];

    /**
     * « Ctrl ↵ » for "Ctrl+Enter", « Entrée » for "Enter" alone, « Échap » for "Escape". A
     * combination is one key label (design-ui.md § 4.15: one <kbd> per combination).
     */
    public static function label(string $combo): string
    {
        $combo = trim($combo);
        if ('' === $combo) {
            throw new \InvalidArgumentException('A keyboard combination cannot be empty.');
        }
        if ('Enter' === $combo) {
            return 'Entrée';
        }
        // "+" alone is a key (the balancing amount), not a separator.
        if ('+' === $combo) {
            return '+';
        }

        $parts = array_map(
            static fn (string $part): string => self::NAMES[$part] ?? $part,
            explode('+', $combo),
        );

        return implode(' ', $parts);
    }
}
