<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The lettering has one door (step 1.6): no class of src/ but
 * App\Service\Accounting\Lettering\Lettering sets or clears a line's letter - the account locked,
 * the rules asked, the audit trail written. Read token by token, so a comment or a string that
 * names the methods is never a finding.
 */
final class LetteringDoorTest extends TestCase
{
    private const string DOOR = 'Service/Accounting/Lettering/Lettering.php';

    private const array METHODS = ['letteringSet', 'letteringClear'];

    public function testOnlyTheLetteringSetsLetters(): void
    {
        $root = \dirname(__DIR__, 2).'/src';
        $offenders = [];
        $door = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $relative = substr($file->getPathname(), \strlen($root) + 1);
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);
            $calls = self::calls($source);
            if (self::DOOR === $relative) {
                $door = $calls;
            } else {
                foreach ($calls as $call) {
                    $offenders[] = $relative.':'.$call;
                }
            }
        }

        self::assertSame([], $offenders, 'Une lettre se pose et s’enlève par Lettering seul.');
        self::assertNotSame([], $door, 'Le scanner doit voir les gestes de Lettering.');
    }

    public function testTheScannerFindsACallAndNothingElse(): void
    {
        $source = <<<'PHP'
            <?php
            // $line->letteringSet('A') in a comment is no finding, nor is '->letteringClear()' in a string.
            $line->letteringSet('A', $day);
            $line?->letteringClear();
            $line->getLetter();
            PHP;

        self::assertSame(['3 letteringSet()', '4 letteringClear()'], self::calls($source));
    }

    /** @return list<string> */
    private static function calls(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn (array|string $token): bool => !\is_array($token) || !\in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true),
        ));
        $calls = [];
        foreach ($tokens as $i => $token) {
            if (\is_array($token) && \T_STRING === $token[0] && \in_array($token[1], self::METHODS, true)) {
                $previous = $tokens[$i - 1] ?? null;
                if (\is_array($previous) && \in_array($previous[0], [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR], true)) {
                    $calls[] = $token[2].' '.$token[1].'()';
                }
            }
        }

        return $calls;
    }
}
