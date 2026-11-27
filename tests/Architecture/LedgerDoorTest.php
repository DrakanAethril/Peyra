<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * « Une écriture est équilibrée ou n'existe pas » (README of the plan, § 8): the Ledger is the one
 * door of the entries. No class of src/ but App\Service\Accounting\Ledger\Ledger makes an Entry or
 * an EntryLine, nor calls the entry's `ledger…` methods that change it. (LedgerGuard refuses at
 * flush what would still go around it; this test says who may write.).
 *
 * Read token by token, so a comment or a string that names them is never a finding.
 */
final class LedgerDoorTest extends TestCase
{
    private const string LEDGER = 'Service/Accounting/Ledger/Ledger.php';

    /** The classes only the Ledger makes. */
    private const array ENTITIES = ['Entry', 'EntryLine'];

    /** The entry's methods only the Ledger calls. */
    private const array METHODS = ['ledgerRewrite', 'ledgerReplaceLines', 'ledgerValidate'];

    public function testOnlyTheLedgerWritesEntries(): void
    {
        $root = \dirname(__DIR__, 2).'/src';
        $offenders = [];
        foreach (self::files($root) as $relative => $path) {
            if (self::LEDGER === $relative) {
                continue;
            }
            $source = file_get_contents($path);
            self::assertIsString($source);
            foreach (self::writes($source) as $finding) {
                $offenders[] = $relative.':'.$finding;
            }
        }

        self::assertSame([], $offenders, 'Une écriture se crée et se change par le Ledger seul (les modules passent par AccountingGateway).');
    }

    public function testTheLedgerItselfIsFound(): void
    {
        $source = file_get_contents(\dirname(__DIR__, 2).'/src/'.self::LEDGER);
        self::assertIsString($source);

        self::assertNotSame([], self::writes($source), 'Le scanner doit voir les gestes du Ledger.');
    }

    public function testTheScannerFindsEveryWayOfWritingAnEntry(): void
    {
        $source = <<<'PHP'
            <?php
            // new Entry() in a comment is no finding, nor is 'new EntryLine(' in a string.
            $a = new Entry($dossier, $journal);
            $b = new \App\Entity\Accounting\EntryLine($a, 1);
            $a->ledgerValidate(1, $day);
            $a?->ledgerReplaceLines([]);
            $c = new RegulatoryFileEntry();
            $d = $entry->getLines();
            PHP;

        self::assertSame(['3 new Entry', '4 new EntryLine', '5 ledgerValidate()', '6 ledgerReplaceLines()'], self::writes($source));
    }

    /** @return list<string> each write, as « line what » */
    private static function writes(string $source): array
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
            if (\T_NEW === $token[0]) {
                $class = $tokens[$i + 1] ?? null;
                if (\is_array($class) && \in_array($class[0], [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED], true)) {
                    $parts = explode('\\', $class[1]);
                    $name = end($parts);
                    if (\in_array($name, self::ENTITIES, true)) {
                        $findings[] = $token[2].' new '.$name;
                    }
                }
                continue;
            }
            if (\T_STRING === $token[0] && \in_array($token[1], self::METHODS, true)) {
                $previous = $tokens[$i - 1] ?? null;
                if (\is_array($previous) && \in_array($previous[0], [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR], true)) {
                    $findings[] = $token[2].' '.$token[1].'()';
                }
            }
        }

        return $findings;
    }

    /** @return array<string, string> path relative to src/ => absolute path */
    private static function files(string $root): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                $files[substr($file->getPathname(), \strlen($root) + 1)] = $file->getPathname();
            }
        }
        ksort($files);

        return $files;
    }
}
