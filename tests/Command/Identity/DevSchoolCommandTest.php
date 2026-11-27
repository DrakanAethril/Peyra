<?php

declare(strict_types=1);

namespace App\Tests\Command\Identity;

use App\Repository\Identity\SchoolClassRepository;
use App\Repository\Identity\SchoolYearRepository;
use App\Repository\Identity\TeacherClassRepository;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** The development school builds on an empty database, and leaves an existing one alone. */
final class DevSchoolCommandTest extends KernelTestCase
{
    public function testBuildsTheDevelopmentSchoolOnce(): void
    {
        $tester = new CommandTester(new Application(self::bootKernel())->find('app:dev:school'));

        self::assertSame(0, $tester->execute([]));
        $years = self::getContainer()->get(SchoolYearRepository::class);
        $current = $years->findCurrent();
        self::assertSame('2026-2027', $current?->getLabel());
        $classes = self::getContainer()->get(SchoolClassRepository::class);
        $last = $years->findPrevious($current);
        self::assertNotNull($last);
        [$lastCg1] = $classes->findByYear($last);
        self::assertSame('BTS CG 2 · 2026-2027', $lastCg1->getNextClass()?->getLabel());
        [$cg1] = $classes->findByYear($current);
        self::assertSame(['Durand Sophie', 'Martin Jean-Pierre'], array_map(static fn ($t): string => $t->getTeacher()->getSortName(), self::getContainer()->get(TeacherClassRepository::class)->findByClass($cg1)));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('rien n’est fait', $tester->getDisplay());
        self::assertCount(2, $years->findAllNewestFirst());
    }
}
