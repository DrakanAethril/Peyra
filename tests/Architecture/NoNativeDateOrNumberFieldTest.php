<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * A date is typed in Px:DateInput, an amount in Px:AmountInput (CLAUDE.md, « Cross-cutting building
 * blocks »): an `<input type="date">` completes and proposes against the computer's clock, never
 * the dossier's, and shows the browser's own format; a `type="number"` field reads « 1 234,56 »
 * as nothing at all. No template may draw one, whether as raw HTML or through Px:Input.
 */
final class NoNativeDateOrNumberFieldTest extends TestCase
{
    private const string PATTERN = '/(?<![\w-])type\s*=\s*["\'](date|datetime-local|month|week|time|number)["\']/';

    public function testNoTemplateDrawsANativeDateOrNumberField(): void
    {
        $offenders = [];
        foreach ((new Finder())->files()->in(\dirname(__DIR__, 2).'/templates')->name('*.twig') as $file) {
            foreach (self::offences($file->getContents()) as $offence) {
                $offenders[] = $file->getRelativePathname().' : type="'.$offence.'"';
            }
        }

        self::assertSame([], $offenders, 'Un champ de date est un Px:DateInput, un montant un Px:AmountInput.');
    }

    public function testTheCheckRecognisesBothForms(): void
    {
        self::assertSame(['date'], self::offences('<twig:Px:Input id="starts_on" type="date" value=""/>'));
        self::assertSame(['number'], self::offences("<input type='number' name='x'>"));
        self::assertSame([], self::offences('<input type="text" data-type="date"> <twig:Px:DateInput id="d"/>'));
    }

    /** @return list<string> */
    private static function offences(string $source): array
    {
        preg_match_all(self::PATTERN, $source, $matches);

        return $matches[1];
    }
}
