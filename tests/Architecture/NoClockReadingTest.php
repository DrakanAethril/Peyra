<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * No business calculation reads the server clock (README of the plan, § 8): each dossier has its
 * own date, given by DossierClock and moved by the teacher. Real time serves the audit trail only.
 *
 * Every PHP file under src/Service/ is read token by token - so a comment or a string that merely
 * names time() is never a finding - outside the closed list of directories allowed to read the
 * clock: Shared (the technical bricks) and the audit trail.
 */
final class NoClockReadingTest extends TestCase
{
    /** Directories of src/Service/ allowed to read the real clock. */
    private const array EXEMPT = ['Shared', 'Audit'];

    /** Functions that read the current time when called (`now` is Symfony Clock's). */
    private const array CLOCK_FUNCTIONS = ['time', 'date', 'gmdate', 'microtime', 'hrtime', 'mktime', 'gmmktime', 'strtotime', 'date_create', 'date_create_immutable', 'getdate', 'localtime', 'idate', 'now'];

    /** Relative date strings that make a DateTime read the clock. */
    private const array RELATIVE_DATES = ['now', 'today', 'tomorrow', 'yesterday', 'midnight', 'noon'];

    public function testNoServiceReadsTheServerClock(): void
    {
        $root = \dirname(__DIR__, 2).'/src/Service';
        $offenders = [];
        foreach (self::servicesFiles($root) as $relative => $path) {
            $source = file_get_contents($path);
            self::assertIsString($source);
            foreach (self::clockReadings($source) as $finding) {
                $offenders[] = $relative.':'.$finding;
            }
        }

        self::assertSame([], $offenders, 'Un calcul métier lit l\'horloge du serveur : passer par DossierClock.');
    }

    public function testTheScannerFindsEveryWayOfReadingTheClock(): void
    {
        $source = <<<'PHP'
            <?php
            use Psr\Clock\ClockInterface;
            $a = new \DateTimeImmutable();
            $b = new DateTime;
            $c = new \DateTime('now');
            $d = time();
            $e = \date('Y');
            $f = new \DateTimeImmutable('Tomorrow');
            PHP;

        self::assertSame(
            ['2 ClockInterface', '3 new DateTimeImmutable()', '4 new DateTime()', "5 new DateTime('now')", '6 time()', '7 date()', "8 new DateTimeImmutable('Tomorrow')"],
            self::clockReadings($source),
        );
    }

    public function testTheScannerLeavesDatedValuesCommentsAndMethodsAlone(): void
    {
        $source = <<<'PHP'
            <?php
            // time() and new \DateTime() are only named here.
            $a = new \DateTimeImmutable('2026-01-01');
            $b = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
            $c = $entry->date();
            $d = Period::time();
            $e = 'date()';
            function date_of(): void {}
            $f = new DateTime($dossierDate);
            PHP;

        self::assertSame([], self::clockReadings($source));
    }

    /**
     * @return array<string, string> path relative to src/Service => absolute path
     */
    private static function servicesFiles(string $root): array
    {
        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $relative = substr($file->getPathname(), \strlen($root) + 1);
            if (\in_array(explode('/', $relative)[0], self::EXEMPT, true)) {
                continue;
            }
            $files[$relative] = $file->getPathname();
        }
        ksort($files);

        return $files;
    }

    /**
     * @return list<string> each reading of the clock, as « line what »
     */
    private static function clockReadings(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn (array|string $token): bool => !\is_array($token) || !\in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true),
        ));

        $findings = [];
        foreach ($tokens as $i => $token) {
            if (!\is_array($token)) {
                continue;
            }
            [$kind, $text, $line] = $token;
            $name = ltrim($text, '\\');

            if (\in_array($kind, [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED], true) && str_ends_with($name, 'ClockInterface')) {
                $findings[] = $line.' ClockInterface';
                continue;
            }

            if (\T_NEW === $kind) {
                $class = $tokens[$i + 1] ?? null;
                if (!\is_array($class) || !\in_array(ltrim($class[1], '\\'), ['DateTime', 'DateTimeImmutable'], true)) {
                    continue;
                }
                $className = ltrim($class[1], '\\');
                $open = $tokens[$i + 2] ?? null;
                $argument = $tokens[$i + 3] ?? null;
                if ('(' !== $open || ')' === $argument) {
                    $findings[] = $line.' new '.$className.'()';
                } elseif (\is_array($argument) && \T_CONSTANT_ENCAPSED_STRING === $argument[0]
                    && \in_array(strtolower(trim($argument[1], '\'"')), self::RELATIVE_DATES, true)) {
                    $findings[] = $line.' new '.$className.'('.$argument[1].')';
                }
                continue;
            }

            if (\in_array($kind, [\T_STRING, \T_NAME_FULLY_QUALIFIED], true) && \in_array(strtolower($name), self::CLOCK_FUNCTIONS, true)) {
                $previous = $tokens[$i - 1] ?? null;
                $isCall = '(' === ($tokens[$i + 1] ?? null);
                $isMember = \is_array($previous) && \in_array($previous[0], [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_FUNCTION, \T_NEW, \T_CONST], true);
                if ($isCall && !$isMember) {
                    $findings[] = $line.' '.strtolower($name).'()';
                }
            }
        }

        return $findings;
    }
}
