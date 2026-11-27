<?php

declare(strict_types=1);

namespace App\Tests\Command\Dossier;

use App\Entity\Dossier\Exercise;
use App\Entity\Dossier\Period;
use App\Enum\Dossier\DossierKind;
use App\Repository\Dossier\DossierRepository;
use App\Service\Dossier\DossierContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** app:demo:install puts Menuiserie Vasseur SARL in the common library once, from fixtures/demo/. */
final class DemoInstallCommandTest extends KernelTestCase
{
    public function testInstallsTheTemplateOnceWithItsExercises(): void
    {
        $tester = new CommandTester(new Application(self::bootKernel())->find('app:demo:install'));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Menuiserie Vasseur SARL installé dans la bibliothèque commune', $tester->getDisplay());
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('déjà installé', $tester->getDisplay());

        $templates = self::getContainer()->get(DossierRepository::class)->findCommonTemplates();
        self::assertCount(1, $templates);
        $vasseur = $templates[0];
        self::assertSame(DossierKind::Template, $vasseur->getKind());
        self::assertNull($vasseur->getOwner());
        self::assertSame('2026-01-01', $vasseur->getDossierDate()->format('Y-m-d'));
        self::assertSame('99941273600018', $vasseur->getCompany()->getSiret());
        self::assertSame(8_000_000, $vasseur->getCompany()->getCapital()->cents);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        [$exercises, $closedPeriods] = self::getContainer()->get(DossierContext::class)->within($vasseur, static fn (): array => [
            $entityManager->getRepository(Exercise::class)->findBy([], ['startsOn' => 'ASC']),
            $entityManager->getRepository(Period::class)->count(['state' => 'closed']),
        ]);
        self::assertSame(['2025', '2026'], array_map(static fn (Exercise $exercise): string => $exercise->getCode(), $exercises));
        self::assertSame([true, false], array_map(static fn (Exercise $exercise): bool => $exercise->isClosed(), $exercises));
        self::assertSame(12, $closedPeriods);
    }
}
