<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Enum\Dossier\DossierKind;
use App\Service\Accounting\Chart\ChartInstaller;
use App\Service\Accounting\Chart\ReferenceCharts;
use App\Service\Dossier\Exercises;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A dossier saved with its exercise 2026 (twelve periods), its date the 15/03/2026, through the
 * services the application uses - for a kernel or web test. In a file of its own: ParaTest hands
 * out files.
 */
trait SavesDossiers
{
    private function savedDossier(DossierKind $kind, ?User $owner, ?SchoolYear $year = null, string $denomination = 'Menuiserie Vasseur'): Dossier
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $dossier = new Dossier($kind, DossierFixtures::company($denomination), new \DateTimeImmutable('2026-03-15'), $owner, $year, new \DateTimeImmutable('2026-11-27 10:00'), null);
        $entityManager->persist($dossier);
        $entityManager->flush();
        static::getContainer()->get(Exercises::class)->open($dossier, '2026', new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'));
        $entityManager->flush();

        return $dossier;
    }

    /** The PCG 2026 copied into the dossier's plan, as a dossier made for 2026 receives it. */
    private function installPlan(Dossier $dossier): void
    {
        $chart = static::getContainer()->get(ReferenceCharts::class)->get('pcg-2026');
        \assert(null !== $chart);
        static::getContainer()->get(ChartInstaller::class)->install($dossier, $chart, 'test');
        static::getContainer()->get(EntityManagerInterface::class)->flush();
    }
}
