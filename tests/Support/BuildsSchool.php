<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Identity\SchoolClass;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Enum\Identity\ClassLevel;
use App\Service\Identity\DirectoryGroupMappings;
use App\Service\Identity\SchoolClasses;
use App\Service\Identity\SchoolYears;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Years, classes and the mapping written through the services the administration screens call,
 * for the tests that are not about those screens. In a file of its own: ParaTest hands out files.
 */
trait BuildsSchool
{
    /**
     * A year (current when asked) with « BTS CG 1 » and « BTS CG 2 », `cg-1` and `cg-2` mapped to them.
     *
     * @return array{SchoolYear, SchoolClass, SchoolClass}
     */
    private function buildYear(KernelBrowser $client, User $actor, int $startYear, bool $current): array
    {
        $container = $client->getContainer();
        $at = new \DateTimeImmutable('2026-11-27 10:00');
        $years = $container->get(SchoolYears::class);
        $year = $years->create(new \DateTimeImmutable($startYear.'-09-01'), new \DateTimeImmutable(($startYear + 1).'-08-31'), false, $actor, $at);
        \assert($year instanceof SchoolYear);
        if ($current) {
            $years->makeCurrent($year, $actor, $at);
        }
        $classes = $container->get(SchoolClasses::class);
        $cg1 = $classes->create($year, 'BTS CG 1', ClassLevel::First, $actor, $at);
        $cg2 = $classes->create($year, 'BTS CG 2', ClassLevel::Second, $actor, $at);
        \assert($cg1 instanceof SchoolClass && $cg2 instanceof SchoolClass);
        $container->get(DirectoryGroupMappings::class)->save($year, ['cg-1' => $cg1, 'cg-2' => $cg2], $actor, $at);

        return [$year, $cg1, $cg2];
    }
}
