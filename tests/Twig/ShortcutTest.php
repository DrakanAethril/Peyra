<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Twig\Shortcut;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShortcutTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function labels(): iterable
    {
        yield 'function key' => ['F10', 'F10'];
        yield 'one key label per combination' => ['Ctrl+K', 'Ctrl K'];
        yield 'Ctrl Enter reads as the return arrow' => ['Ctrl+Enter', 'Ctrl ↵'];
        yield 'Enter alone is a word' => ['Enter', 'Entrée'];
        yield 'Escape in French' => ['Escape', 'Échap'];
        yield 'Shift is Maj' => ['Shift+Tab', 'Maj Tab'];
        yield 'arrows' => ['Ctrl+Alt+ArrowRight', 'Ctrl Alt →'];
        yield 'delete' => ['Ctrl+Delete', 'Ctrl Suppr'];
        yield 'the balancing plus' => ['+', '+'];
        yield 'a character' => ['?', '?'];
        yield 'pages' => ['PageDown', 'Page suiv.'];
    }

    #[DataProvider('labels')]
    public function testLabelsACombinationInFrench(string $combo, string $label): void
    {
        self::assertSame($label, Shortcut::label($combo));
    }

    public function testRefusesAnEmptyCombination(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Shortcut::label(' ');
    }

    /**
     * The browser half (assets/shortcuts/registry.js) carries the same table: the two must not drift.
     */
    public function testTheBrowserRegistrySpeaksTheSameLabels(): void
    {
        $registry = (string) file_get_contents(\dirname(__DIR__, 2).'/assets/shortcuts/registry.js');
        foreach (['Shift' => 'Maj', 'Enter' => '↵', 'Escape' => 'Échap', 'Delete' => 'Suppr', 'ArrowRight' => '→', 'PageDown' => 'Page suiv.'] as $key => $label) {
            self::assertStringContainsString(\sprintf("%s: '%s'", $key, $label), $registry, $key);
            self::assertStringContainsString($label, Shortcut::label($key.'+X'), $key);
        }
    }
}
