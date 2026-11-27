<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Every icon a template names has its file in assets/icons/. Production renders nothing for a
 * missing icon rather than failing the page (config/packages/ux_icons.yaml), so a typo would
 * silently empty a button: this is where it breaks instead. Names are read where templates write
 * them: <twig:ux:icon name="…">, ux_icon('…'), and the `icon` prop of a Px component
 * (icon="…", icon: '…').
 */
final class IconNamesTest extends TestCase
{
    private const array PATTERNS = [
        '/<twig:ux:icon\s+name="([a-z0-9-]+)"/',
        '/ux_icon\(\s*\'([a-z0-9-]+)\'/',
        '/\sicon="([a-z0-9-]+)"/',
        '/\bicon:\s*\'([a-z0-9-]+)\'/',
    ];

    public function testEveryIconATemplateNamesExists(): void
    {
        $root = \dirname(__DIR__, 2);
        $missing = [];
        $seen = 0;
        foreach ((new Finder())->files()->in($root.'/templates')->name('*.twig') as $file) {
            foreach (self::PATTERNS as $pattern) {
                preg_match_all($pattern, $file->getContents(), $matches);
                foreach ($matches[1] as $name) {
                    ++$seen;
                    if (!is_file($root.'/assets/icons/'.$name.'.svg')) {
                        $missing[] = $file->getRelativePathname().': '.$name;
                    }
                }
            }
        }

        self::assertGreaterThan(20, $seen, 'The patterns no longer find the icons the templates name.');
        self::assertSame([], $missing);
    }

    /** The drawing rules of design-ui.md § 5: a 24 grid, no fill, a 1.75 stroke in currentColor. */
    public function testEveryIconFileFollowsTheDrawingRules(): void
    {
        $wrong = [];
        foreach ((new Finder())->files()->in(\dirname(__DIR__, 2).'/assets/icons')->name('*.svg') as $file) {
            $svg = $file->getContents();
            foreach (['viewBox="0 0 24 24"', 'fill="none"', 'stroke="currentColor"', 'stroke-width="1.75"'] as $rule) {
                if (!str_contains($svg, $rule)) {
                    $wrong[] = $file->getFilename().': '.$rule;
                }
            }
        }

        self::assertSame([], $wrong);
    }
}
