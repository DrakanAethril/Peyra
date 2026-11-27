<?php

declare(strict_types=1);

namespace App\Tests\Command\Regulatory;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** The command every deployment runs: it reads the repository's files and says what it did with each. */
final class RegulatorySyncCommandTest extends KernelTestCase
{
    public function testInstallsTheRepositoryMillesimes(): void
    {
        $tester = new CommandTester(new Application(self::bootKernel())->find('app:regulatory:sync'));

        self::assertSame(0, $tester->execute([], ['interactive' => false]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('2025.yaml', $display);
        self::assertStringContainsString('2026.yaml', $display);

        self::assertSame(0, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString('Essai à blanc', $tester->getDisplay());
    }
}
