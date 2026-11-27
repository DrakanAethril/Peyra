<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * The design system's two rules a reviewer would otherwise check by eye (design-ui.md § 2):
 * a component reads tokens only, and the token pairs the screens use meet WCAG 2.1 AA.
 */
final class DesignTokensTest extends TestCase
{
    private const string STYLES = __DIR__.'/../../assets/styles';

    /**
     * Rule 1 of § 2.1: no colour written in a component or layout stylesheet. tokens.css holds them
     * all; print.css is the paper's own ink (§ 3.6), outside the screen's tokens.
     */
    public function testComponentsReadTokensOnly(): void
    {
        $found = [];
        foreach ((new Finder())->files()->in([self::STYLES.'/components', self::STYLES.'/layout'])->name('*.css') as $file) {
            $css = (string) preg_replace('~/\*.*?\*/~s', '', $file->getContents());
            if (preg_match_all('/#[0-9a-fA-F]{3,8}\b|\brgba?\(|\bhsla?\(/', $css, $matches)) {
                $found[] = $file->getRelativePathname().': '.implode(', ', array_unique($matches[0]));
            }
        }

        self::assertSame([], $found);
    }

    public function testEveryTokenAComponentReadsIsDeclared(): void
    {
        $declared = $this->tokens();
        $unknown = [];
        foreach ((new Finder())->files()->in(self::STYLES)->name('*.css') as $file) {
            preg_match_all('/var\((--px-[a-z0-9-]+)/', $file->getContents(), $matches);
            foreach ($matches[1] as $name) {
                if (!\array_key_exists($name, $declared) && '--px-value' !== $name) {
                    $unknown[$name] = $file->getRelativePathname().': '.$name;
                }
            }
        }

        self::assertSame([], array_values($unknown));
    }

    /**
     * The pairs of design-ui.md § 2.3, read from tokens.css: a token changed into a pair that no
     * longer passes fails here.
     *
     * @return iterable<string, array{string, string, float}>
     */
    public static function pairs(): iterable
    {
        foreach (['--px-bg', '--px-surface', '--px-surface-sunken'] as $ground) {
            yield 'strong text on '.$ground => ['--px-text-strong', $ground, 4.5];
            yield 'text on '.$ground => ['--px-text', $ground, 4.5];
            yield 'muted text on '.$ground => ['--px-text-muted', $ground, 4.5];
            yield 'action on '.$ground => ['--px-action', $ground, 4.5];
            yield 'input outline on '.$ground => ['--px-border-control', $ground, 3.0];
        }
        foreach (['--px-action-soft', '--px-warning-row', '--px-danger-row', '--px-surface-inset', '--px-surface-viewer', '--px-mission-bg'] as $ground) {
            yield 'muted text on '.$ground => ['--px-text-muted', $ground, 4.5];
        }
        yield 'white on action' => ['--px-text-on-action', '--px-action', 4.5];
        yield 'white on action hover' => ['--px-text-on-action', '--px-action-hover', 4.5];
        yield 'key inside the primary button' => ['--px-on-action-kbd', '--px-action-hover', 4.5];
        yield 'info pill' => ['--px-info-text', '--px-info-tint', 4.5];
        yield 'success pill' => ['--px-success-text', '--px-success-tint', 4.5];
        yield 'warning pill' => ['--px-warning-text', '--px-warning-tint', 4.5];
        yield 'danger pill' => ['--px-danger-text', '--px-danger-tint', 4.5];
        yield 'neutral pill' => ['--px-neutral-text', '--px-neutral-tint', 4.5];
        yield 'role chip' => ['--px-role-text', '--px-role-tint', 4.5];
        yield 'mission banner' => ['--px-mission-text', '--px-mission-bg', 4.5];
        yield 'rail counter' => ['--px-warning-on', '--px-warning', 4.5];
        yield 'white on danger' => ['--px-text-on-action', '--px-danger', 4.5];
        yield 'rail entries' => ['--px-rail-text', '--px-rail-bg', 4.5];
        yield 'rail hovered entry' => ['--px-rail-text', '--px-rail-hover', 4.5];
        yield 'rail active entry' => ['--px-rail-text-active', '--px-rail-active', 4.5];
        yield 'rail labels' => ['--px-rail-label', '--px-rail-bg', 4.5];
        yield 'gold rule of the active entry' => ['--px-rail-accent', '--px-rail-active', 3.0];
        yield 'focus ring on the rail' => ['--px-focus-on-dark', '--px-rail-active', 3.0];
        yield 'focus ring' => ['--px-focus', '--px-surface', 3.0];
        yield 'tooltip' => ['--px-text-on-action', '--px-surface-inverse', 4.5];
        yield 'avatar initials' => ['--px-text', '--px-avatar-bg', 4.5];
        yield 'SQL keyword' => ['--px-code-keyword', '--px-code-bg', 4.5];
    }

    #[DataProvider('pairs')]
    public function testContrast(string $foreground, string $background, float $minimum): void
    {
        $tokens = $this->tokens();
        $ratio = self::contrast($this->colour($tokens, $foreground), $this->colour($tokens, $background));

        self::assertGreaterThanOrEqual($minimum, round($ratio, 2), \sprintf('%s on %s: %.2f:1', $foreground, $background, $ratio));
    }

    public function testContrastFormulaOnKnownValues(): void
    {
        self::assertEqualsWithDelta(21.0, self::contrast('#000000', '#ffffff'), 0.01);
        self::assertEqualsWithDelta(5.65, self::contrast('#1b6ba8', '#ffffff'), 0.01, 'design-ui.md § 2.3: action on white.');
        self::assertEqualsWithDelta(3.47, self::contrast('#7d8b9c', '#ffffff'), 0.01, 'design-ui.md § 2.3: input outline.');
    }

    /** @return array<string, string> */
    private function tokens(): array
    {
        $css = (string) file_get_contents(self::STYLES.'/tokens.css');
        preg_match_all('/(--px-[a-z0-9-]+)\s*:\s*([^;]+);/', $css, $matches, \PREG_SET_ORDER);
        $tokens = [];
        foreach ($matches as $match) {
            $tokens[$match[1]] ??= trim($match[2]);
        }

        return $tokens;
    }

    /** @param array<string, string> $tokens */
    private function colour(array $tokens, string $name): string
    {
        $value = $tokens[$name] ?? self::fail('Unknown token '.$name);
        if (preg_match('/^var\((--px-[a-z0-9-]+)\)$/', $value, $alias)) {
            return $this->colour($tokens, $alias[1]);
        }
        self::assertMatchesRegularExpression('/^#[0-9a-fA-F]{6}$/', $value, $name.' is not a plain colour');

        return $value;
    }

    private static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + .05) / (min($la, $lb) + .05);
    }

    private static function luminance(string $hex): float
    {
        $channels = array_map(
            static function (string $pair): float {
                $c = hexdec($pair) / 255;

                return $c <= .03928 ? $c / 12.92 : (($c + .055) / 1.055) ** 2.4;
            },
            str_split(substr($hex, 1), 2),
        );

        return .2126 * $channels[0] + .7152 * $channels[1] + .0722 * $channels[2];
    }
}
