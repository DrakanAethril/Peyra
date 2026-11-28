<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * One service decides what each assistance level shows, proposes and stops (plan, step 2.8):
 * App\Service\Shared\Assistance\Assistance. No other class names a level to compare with it - it
 * asks Assistance - so a level means the same thing on every screen. The resolution of the level
 * (StudentAssistanceLevels: its default) and the enum itself are the only other places.
 */
final class AssistanceDecidesTest extends TestCase
{
    public function testNoClassComparesTheLevelItself(): void
    {
        $root = \dirname(__DIR__, 2);
        $offenders = [];
        foreach ((new Finder())->files()->in($root.'/src')->name('*.php')->sortByName() as $file) {
            $path = substr($file->getPathname(), \strlen($root) + 1);
            if (str_starts_with($path, 'src/Service/Shared/Assistance/') || 'src/Enum/Accounting/AssistanceLevel.php' === $path) {
                continue;
            }
            if (1 === preg_match('/AssistanceLevel::(Guided|Blocking|Warnings|None)\b/', $file->getContents())) {
                $offenders[] = $path;
            }
        }
        foreach ((new Finder())->files()->in($root.'/templates')->name('*.twig')->sortByName() as $file) {
            if (1 === preg_match('/assistance\w*\.level\.(value|name)|level\.(blocks|controls)\b/', $file->getContents())) {
                $offenders[] = substr($file->getPathname(), \strlen($root) + 1);
            }
        }

        self::assertSame([], $offenders, 'Ask App\Service\Shared\Assistance\Assistance instead of comparing the assistance level.');
    }
}
