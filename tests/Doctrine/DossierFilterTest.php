<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Doctrine\Filter\DossierContextMissing;
use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\Exercise;
use App\Entity\Dossier\Period;
use App\Enum\Dossier\DossierKind;
use App\Repository\Dossier\DossierRepository;
use App\Repository\Dossier\ExerciseRepository;
use App\Service\Dossier\DossierContext;
use App\Tests\Support\SavesDossiers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The Doctrine filter `dossier` (README of the plan, § 8): a DossierOwned row is read inside a
 * dossier's context only - without one the query throws; with one it never sees another dossier;
 * a reset (between two requests of the worker) forgets the context.
 */
final class DossierFilterTest extends KernelTestCase
{
    use SavesDossiers;
    private EntityManagerInterface $entityManager;
    private DossierContext $context;
    private Dossier $vasseur;
    private Dossier $other;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->context = $container->get(DossierContext::class);
        $this->vasseur = $this->savedDossier(DossierKind::Template, null);
        $this->other = $this->savedDossier(DossierKind::Template, null, denomination: 'Atelier Dumas');
        $this->entityManager->clear();
        $this->vasseur = $this->reload($this->vasseur);
        $this->other = $this->reload($this->other);
    }

    public function testAQueryWithoutContextThrows(): void
    {
        $this->expectException(DossierContextMissing::class);

        $this->entityManager->getRepository(Exercise::class)->findAll();
    }

    public function testADqlQueryWithoutContextThrowsToo(): void
    {
        $this->expectException(DossierContextMissing::class);

        $this->entityManager->createQuery('SELECT p FROM '.Period::class.' p')->getResult();
    }

    public function testAGlobalEntityIsReadWithoutContext(): void
    {
        self::assertCount(2, self::getContainer()->get(DossierRepository::class)->findCommonTemplates());
    }

    public function testAContextReadsItsDossierOnly(): void
    {
        $this->context->enter($this->vasseur);
        $exercises = $this->entityManager->getRepository(Exercise::class)->findAll();
        $periods = $this->entityManager->createQuery('SELECT p FROM '.Period::class.' p')->getResult();

        self::assertCount(1, $exercises);
        self::assertSame($this->vasseur, $exercises[0]->getDossier());
        self::assertCount(12, $periods);
    }

    public function testAnotherDossiersRowIsNotFoundEvenByItsId(): void
    {
        $otherExercise = $this->context->within($this->other, fn (): ?Exercise => self::getContainer()->get(ExerciseRepository::class)->findAt($this->other, new \DateTimeImmutable('2026-06-01')));
        self::assertNotNull($otherExercise);
        $id = $otherExercise->getId();
        $this->entityManager->clear();

        $this->context->enter($this->reload($this->vasseur));

        self::assertNull($this->entityManager->find(Exercise::class, $id));
    }

    public function testWithinAndAcrossGiveTheContextBack(): void
    {
        $this->context->enter($this->vasseur);

        $both = $this->context->across([$this->vasseur, $this->other], fn (): array => $this->entityManager->getRepository(Exercise::class)->findAll());
        $inOther = $this->context->within($this->other, fn (): array => $this->entityManager->getRepository(Exercise::class)->findAll());

        self::assertCount(2, $both);
        self::assertCount(1, $inOther);
        self::assertSame($this->vasseur, $this->context->current());
        self::assertCount(1, $this->entityManager->getRepository(Exercise::class)->findAll());
    }

    public function testAnEmptyListReadsNothing(): void
    {
        self::assertSame([], $this->context->across([], fn (): array => $this->entityManager->getRepository(Exercise::class)->findAll()));
    }

    public function testAResetForgetsTheContext(): void
    {
        $this->context->enter($this->vasseur);
        $this->context->reset();

        self::assertFalse($this->context->has());
        $this->expectException(DossierContextMissing::class);
        $this->entityManager->getRepository(Exercise::class)->findAll();
    }

    public function testLeavingAfterAWithinOutsideAnyContextLeavesNone(): void
    {
        $this->context->within($this->vasseur, fn (): array => $this->entityManager->getRepository(Exercise::class)->findAll());

        self::assertFalse($this->context->has());
        $this->expectException(DossierContextMissing::class);
        $this->entityManager->getRepository(Exercise::class)->findAll();
    }

    private function reload(Dossier $dossier): Dossier
    {
        $reloaded = $this->entityManager->find(Dossier::class, $dossier->getId());
        \assert($reloaded instanceof Dossier);

        return $reloaded;
    }
}
