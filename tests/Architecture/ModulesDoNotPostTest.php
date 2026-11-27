<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * « Les modules ne comptabilisent pas eux-mêmes » (README of the plan, § 8; conception § 5): sales,
 * purchases, inventory, bank, fixed assets, payroll, VAT and the year-end work hand an
 * EntryProposal to AccountingGateway, which applies the mission's behaviour. Only the accounting
 * itself reaches the Ledger: its services (the gateway among them), its screens (the journals'
 * grid, step 1.4) and the demonstration's installer. A file anywhere else that names the Ledger
 * fails this test.
 */
final class ModulesDoNotPostTest extends TestCase
{
    /** Where the Ledger may be named, relative to src/ (a directory ends with a slash). */
    private const array ALLOWED = ['Service/Accounting/', 'Controller/Accounting/', 'Service/Dossier/Demo/DemoInstaller.php'];

    public function testOnlyTheAccountingNamesTheLedger(): void
    {
        $root = \dirname(__DIR__, 2).'/src';
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $relative = substr($file->getPathname(), \strlen($root) + 1);
            if (self::allowed($relative)) {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);
            if (self::namesTheLedger($source)) {
                $offenders[] = $relative;
            }
        }
        sort($offenders);

        self::assertSame([], $offenders, 'Un module demande une écriture à AccountingGateway, il ne l’écrit pas.');
    }

    public function testTheScannerTellsTheLedgerFromItsReader(): void
    {
        self::assertTrue(self::namesTheLedger("<?php\nuse App\\Service\\Accounting\\Ledger\\Ledger;"));
        self::assertTrue(self::namesTheLedger("<?php\nuse App\\Service\\Accounting\\Ledger\\{EntryDraft, Ledger};"));
        self::assertTrue(self::namesTheLedger("<?php\n\$x = \\App\\Service\\Accounting\\Ledger\\Ledger::class;"));
        self::assertFalse(self::namesTheLedger("<?php\nuse App\\Service\\Accounting\\Ledger\\LedgerReader;\nuse App\\Service\\Accounting\\Ledger\\EntryDraft;"));
        self::assertFalse(self::namesTheLedger("<?php\n/** Made by App\\Service\\Accounting\\Ledger\\Ledger alone. */\nclass Entry {}"));
    }

    private static function allowed(string $relative): bool
    {
        foreach (self::ALLOWED as $allowed) {
            if (str_ends_with($allowed, '/') ? str_starts_with($relative, $allowed) : $relative === $allowed) {
                return true;
            }
        }

        return false;
    }

    /** Whether the code - its comments left out - names the Ledger. */
    private static function namesTheLedger(string $source): bool
    {
        $source = implode('', array_map(
            static fn (array|string $token): string => \is_array($token) ? (\in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true) ? ' ' : $token[1]) : $token,
            token_get_all($source),
        ));

        return 1 === preg_match('/App\\\\Service\\\\Accounting\\\\Ledger\\\\Ledger\b/', $source)
            || 1 === preg_match('/App\\\\Service\\\\Accounting\\\\Ledger\\\\\{[^}]*\bLedger\b/', $source);
    }
}
