<?php

declare(strict_types=1);

namespace App\Tests\Service\Dossier\Restore;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierRestoration;
use App\Entity\Identity\User;
use App\Enum\Dossier\DossierKind;
use App\Enum\Dossier\RestorePointOrigin;
use App\Enum\Identity\AccountKind;
use App\Enum\Identity\EntryBasis;
use App\Repository\Dossier\RestorePointRepository;
use App\Service\Dossier\ClassDossierRow;
use App\Service\Dossier\DossierContents;
use App\Service\Dossier\Restore\RestoreBatches;
use App\Service\Dossier\Restore\RestorePoints;
use App\Service\Dossier\Restore\RestoreQueue;
use App\Service\Dossier\Restore\RestoreRefused;
use App\Service\Dossier\Restore\RestoreRunner;
use App\Tests\Support\SavesDossiers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The teacher's gestures on several dossiers at once (lot 1, step 1.8d; design-ux.md § 4.9, step
 * 4): each dossier goes back to its own « État de départ », one without it is left and said, the
 * state left is kept as a point wherever ten points leave room, and posing counts the dossiers
 * already full instead of stopping.
 */
final class RestoreBatchesTest extends KernelTestCase
{
    use SavesDossiers;

    private const string AT = '2026-11-28 09:00';

    private User $teacher;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->teacher = new User('uid:lot', 'prof.lot', 'Prof Lot', AccountKind::Teacher, EntryBasis::TeacherGroup, ['teacher', 'cg-1'], new \DateTimeImmutable(self::AT));
        $this->entityManager()->persist($this->teacher);
        $this->entityManager()->flush();
    }

    public function testEachDossierGoesBackToItsOwnStartAndOneWithoutIsLeft(): void
    {
        [$started, $startedRow] = $this->dossier('Bernard Lucas');
        [, $bareRow] = $this->dossier('Chevalier Manon');
        $this->startPoint($started);
        $atStart = $this->contents($started);
        $this->installPlan($started);
        self::assertNotSame($atStart, $this->contents($started));
        self::getContainer()->get(RestoreQueue::class)->reset();

        $outcome = $this->batches()->resetToStart([$startedRow, $bareRow], true, $this->teacher, new \DateTimeImmutable('2026-11-28 09:10'));

        self::assertSame(1, $outcome->done);
        self::assertSame([['holder' => 'Chevalier Manon', 'reason' => 'pas d’état de départ']], $outcome->skipped);
        self::assertSame('1 dossier remis · 1 laissé : Chevalier Manon (pas d’état de départ).', $outcome->sentence('dossier remis', 'dossiers remis'));
        $work = self::getContainer()->get(RestoreQueue::class)->take();
        self::assertCount(1, $work['restorations']);
        self::assertTrue(self::getContainer()->get(RestoreRunner::class)->restore($work['restorations'][0], new \DateTimeImmutable('2026-11-28 09:11')));
        $started = $this->entityManager()->find(Dossier::class, $started->getId()) ?? throw new \LogicException('Kept.');
        self::assertSame($atStart, $this->contents($started), 'Le dossier reprend son état de départ.');
        $return = $this->entityManager()->find(DossierRestoration::class, $work['restorations'][0]) ?? throw new \LogicException('Kept.');
        self::assertNotNull($return->getSafetyPoint(), 'L’état quitté est posé en point.');
    }

    public function testTheStateLeftIsNotKeptWhereTenPointsLeaveNoRoom(): void
    {
        [$dossier, $row] = $this->dossier('Bernard Lucas');
        $this->startPoint($dossier);
        $this->fill($dossier);

        $outcome = $this->batches()->resetToStart([$row], true, $this->teacher, new \DateTimeImmutable('2026-11-28 09:10'));

        self::assertSame(1, $outcome->done);
        $return = $this->entityManager()->getRepository(DossierRestoration::class)->findOneBy(['dossier' => $dossier]) ?? throw new \LogicException('Asked.');
        self::assertNull($return->getSafetyPoint());
        self::assertSame(10, self::getContainer()->get(RestorePointRepository::class)->countAgainstLimit($dossier));
    }

    public function testPosingCountsTheFullDossiersAndRefusesANameless(): void
    {
        [$full, $fullRow] = $this->dossier('Bernard Lucas');
        [$free, $freeRow] = $this->dossier('Chevalier Manon');
        $this->fill($full);

        $outcome = $this->batches()->pose([$fullRow, $freeRow], 'Point de la classe', $this->teacher, new \DateTimeImmutable(self::AT));

        self::assertSame(1, $outcome->done);
        self::assertSame([['holder' => 'Bernard Lucas', 'reason' => 'ce dossier a déjà 10 points de restauration']], $outcome->skipped);
        self::assertSame(1, self::getContainer()->get(RestorePointRepository::class)->countAgainstLimit($free));

        $this->expectException(RestoreRefused::class);
        $this->batches()->pose([$freeRow], '   ', $this->teacher, new \DateTimeImmutable(self::AT));
    }

    /** @return array{Dossier, ClassDossierRow} a student's dossier and its line */
    private function dossier(string $student): array
    {
        $owner = new User('uid:'.$student, strtolower(str_replace(' ', '.', $student)), $student, AccountKind::Student, EntryBasis::StudentGroup, ['student', 'cg-1'], new \DateTimeImmutable(self::AT));
        $this->entityManager()->persist($owner);
        $dossier = $this->savedDossier(DossierKind::Student, $owner);

        return [$dossier, new ClassDossierRow($dossier, [$owner], null, null, false)];
    }

    /** The « État de départ » a distribution poses, made at once. */
    private function startPoint(Dossier $dossier): void
    {
        $point = self::getContainer()->get(RestorePoints::class)->create($dossier, 'État de départ', RestorePointOrigin::Start, 'test', new \DateTimeImmutable(self::AT));
        $this->entityManager()->flush();
        self::assertTrue(self::getContainer()->get(RestoreRunner::class)->preparePoint((int) $point->getId(), new \DateTimeImmutable(self::AT)));
    }

    /** Ten points posed by hand: the dossier's limit. */
    private function fill(Dossier $dossier): void
    {
        for ($i = 1; $i <= 10; ++$i) {
            self::getContainer()->get(RestorePoints::class)->create($dossier, 'Point '.$i, RestorePointOrigin::Manual, 'test', new \DateTimeImmutable(self::AT));
        }
        $this->entityManager()->flush();
        self::getContainer()->get(RestoreQueue::class)->reset();
    }

    /** @return array<string, int> */
    private function contents(Dossier $dossier): array
    {
        return self::getContainer()->get(DossierContents::class)->of($dossier);
    }

    private function batches(): RestoreBatches
    {
        return self::getContainer()->get(RestoreBatches::class);
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
