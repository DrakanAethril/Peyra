<?php

declare(strict_types=1);

namespace App\Tests\Service\Dossier\Restore;

use App\Entity\Accounting\Entry;
use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierRestoration;
use App\Entity\Dossier\RestorePoint;
use App\Entity\Identity\User;
use App\Enum\Accounting\EntryState;
use App\Enum\Audit\AuditAction;
use App\Enum\Dossier\RestorationState;
use App\Enum\Dossier\RestorePointOrigin;
use App\Enum\Dossier\RestorePointState;
use App\Enum\Identity\AccountKind;
use App\Enum\Identity\EntryBasis;
use App\Repository\Accounting\EntryRepository;
use App\Repository\Accounting\JournalRepository;
use App\Repository\Dossier\PeriodRepository;
use App\Service\Accounting\Ledger\Ledger;
use App\Service\Dossier\Copy\CopyPlan;
use App\Service\Dossier\Copy\DossierCopier;
use App\Service\Dossier\Copy\DossierEraser;
use App\Service\Dossier\Demo\DemoInstaller;
use App\Service\Dossier\DossierContext;
use App\Service\Dossier\Restore\Restorations;
use App\Service\Dossier\Restore\RestorePointRules;
use App\Service\Dossier\Restore\RestorePoints;
use App\Service\Dossier\Restore\RestoreQueue;
use App\Service\Dossier\Restore\RestoreRefused;
use App\Service\Dossier\Restore\RestoreRunner;
use App\Service\Shared\Storage\DossierFiles;
use App\Tests\Support\CanonicalDossierExport;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Restore points (lot 1, step 1.8b; design-ux.md § 4.9) on the demonstration dossier: a point is
 * the dossier of its moment, a return gives that moment back whole - rows and files - or leaves the
 * dossier as it was, and the limits and refusals hold.
 */
final class RestorePointsTest extends KernelTestCase
{
    private const string AT = '2026-11-28 09:00';

    private Dossier $dossier;
    private User $teacher;

    protected function setUp(): void
    {
        self::bootKernel();
        $installer = self::getContainer()->get(DemoInstaller::class);
        $installer->installTemplate('test', new \DateTimeImmutable(self::AT));
        $this->dossier = $installer->findTemplate() ?? throw new \LogicException('The demonstration is installed.');
        $this->teacher = new User('uid:restaurateur', 'prof.restaurateur', 'Prof Restaurateur', AccountKind::Teacher, EntryBasis::TeacherGroup, ['teacher', 'cg-1'], new \DateTimeImmutable(self::AT));
        $this->entityManager()->persist($this->teacher);
        $this->entityManager()->flush();
        self::getContainer()->get(DossierFiles::class)->storeContents($this->dossierId(), 'pieces', 'pdf', '%PDF-1.7 facture');
    }

    public function testAPointIsACopyOfTheDossierMadeAfterTheRequest(): void
    {
        $point = $this->points()->pose($this->dossier, '  Avant validation  ', $this->teacher, new \DateTimeImmutable(self::AT));

        self::assertSame('Avant validation', $point->getName());
        self::assertSame(RestorePointState::Preparing, $point->getState());
        self::assertSame(RestorePointOrigin::Manual, $point->getOrigin());
        self::assertSame($this->dossier->getDossierDate()->format('Y-m-d'), $point->getDossierDate()->format('Y-m-d'));
        self::assertSame(['points' => [$point->getId()], 'restorations' => []], self::getContainer()->get(RestoreQueue::class)->take());

        self::assertTrue($this->runner()->preparePoint((int) $point->getId(), new \DateTimeImmutable('2026-11-28 09:01')));
        self::assertFalse($this->runner()->preparePoint((int) $point->getId(), new \DateTimeImmutable('2026-11-28 09:02')), 'Une copie faite ne se refait pas.');

        $point = $this->reload($point);
        self::assertTrue($point->isReady());
        $export = $this->export();
        self::assertSame($export->of($this->dossier), $export->of($point->getSnapshot()));
        self::assertCount(1, self::getContainer()->get(DossierFiles::class)->keys((int) $point->getSnapshot()->getId()));
        self::assertSame(1, $this->trailCount(AuditAction::RestorePointPosed));
    }

    public function testGoingBackGivesThePointsMomentBackAndKeepsTheStateItLeaves(): void
    {
        $point = $this->readyPoint('Avant la contrepassation');
        $atPoint = $this->export()->of($this->dossier);
        $filesAtPoint = $this->files()->keys($this->dossierId());

        // Work after the point: an entry reversed, a file added.
        $entry = $this->firstValidated();
        $this->within(fn () => self::getContainer()->get(Ledger::class)->reverse($entry, new \DateTimeImmutable('2026-01-31'), $this->teacher));
        $added = $this->files()->storeContents($this->dossierId(), 'pieces', 'pdf', '%PDF-1.7 avoir');
        $current = $this->export()->of($this->dossier);
        self::assertNotSame($atPoint, $current);

        $restoration = self::getContainer()->get(Restorations::class)->returnTo($point, true, $this->teacher, new \DateTimeImmutable('2026-11-28 09:10'));
        self::assertTrue($restoration->isRunning());
        self::assertSame(RestorePointRules::safetyName('Avant la contrepassation'), $restoration->getSafetyPoint()?->getName());
        self::assertTrue($this->runner()->restore((int) $restoration->getId(), new \DateTimeImmutable('2026-11-28 09:11')));

        $restoration = $this->entityManager()->find(DossierRestoration::class, $restoration->getId()) ?? throw new \LogicException('The return is kept.');
        self::assertSame(RestorationState::Done, $restoration->getState());
        $dossier = $this->entityManager()->find(Dossier::class, $this->dossierId()) ?? throw new \LogicException('The dossier is there.');
        $this->dossier = $dossier;
        self::assertSame($atPoint, $this->export()->of($dossier));
        self::assertSame($filesAtPoint, $this->files()->keys($this->dossierId()));
        self::assertNotContains($added, $this->files()->keys($this->dossierId()));
        self::assertSame('2026-03-31', $dossier->getDossierDate()->format('Y-m-d'), 'La date du dossier ne bouge pas.');
        // The state it left is a point of its own, ready.
        $safety = $restoration->getSafetyPoint() ?? throw new \LogicException('A point of the state left.');
        self::assertTrue($safety->isReady());
        self::assertSame($current, $this->export()->of($safety->getSnapshot()));
        self::assertSame(1, $this->trailCount(AuditAction::DossierRestored));
    }

    public function testAReturnThatStopsLeavesTheDossierAsItWas(): void
    {
        $point = $this->readyPoint('Point abîmé');
        $before = $this->export()->of($this->dossier);
        $filesBefore = $this->files()->keys($this->dossierId());
        // A line of the copy names an account of another dossier: the copy fails halfway, after the erasure.
        $connection = self::getContainer()->get(Connection::class);
        $snapshot = (int) $point->getSnapshot()->getId();
        $foreign = $connection->fetchOne('SELECT id FROM account WHERE dossier_id = ? ORDER BY id LIMIT 1', [$this->dossierId()]);
        $connection->executeStatement('UPDATE entry_line SET account_id = ? WHERE dossier_id = ? ORDER BY id LIMIT 1', [$foreign, $snapshot]);

        $restoration = self::getContainer()->get(Restorations::class)->returnTo($point, false, $this->teacher, new \DateTimeImmutable('2026-11-28 09:10'));
        self::assertTrue($this->runner()->restore((int) $restoration->getId(), new \DateTimeImmutable('2026-11-28 09:11')));

        $restoration = $this->entityManager()->find(DossierRestoration::class, $restoration->getId()) ?? throw new \LogicException('The return is kept.');
        self::assertSame(RestorationState::Failed, $restoration->getState());
        self::assertStringContainsString('le dossier est resté dans l’état d’avant', (string) $restoration->getFailure());
        self::assertSame($before, $this->export()->of($this->dossier));
        self::assertSame($filesBefore, $this->files()->keys($this->dossierId()));
        self::assertSame(1, $this->trailCount(AuditAction::DossierRestorationFailed));
    }

    public function testACopyThatFailsSaysSoAndTouchesNothing(): void
    {
        $point = $this->points()->pose($this->dossier, 'Copie impossible', $this->teacher, new \DateTimeImmutable(self::AT));
        // The point's copy already holds rows: the copier refuses to land on them.
        self::getContainer()->get(DossierCopier::class)->copy($this->dossier, $point->getSnapshot(), \App\Enum\Dossier\CopyKind::Template);
        $before = $this->export()->of($this->dossier);

        self::assertTrue($this->runner()->preparePoint((int) $point->getId(), new \DateTimeImmutable('2026-11-28 09:01')));

        $point = $this->reload($point);
        self::assertSame(RestorePointState::Failed, $point->getState());
        self::assertSame(RestorePoints::FAILURE, $point->getFailure());
        self::assertSame($before, $this->export()->of($this->entityManager()->find(Dossier::class, $this->dossierId()) ?? $this->dossier));
        self::assertSame(1, $this->trailCount(AuditAction::RestorePointFailed));
    }

    public function testTheLimitsAndTheRefusals(): void
    {
        $points = $this->points();
        foreach (['', str_repeat('x', 81)] as $name) {
            try {
                $points->pose($this->dossier, $name, $this->teacher, new \DateTimeImmutable(self::AT));
                self::fail('Un nom vide ou trop long est refusé.');
            } catch (RestoreRefused $refused) {
                self::assertSame('name', $refused->field);
            }
        }
        $preparing = $points->pose($this->dossier, 'Point 1', $this->teacher, new \DateTimeImmutable(self::AT));
        try {
            $points->delete($preparing, $this->teacher);
            self::fail('Un point en préparation ne se supprime pas.');
        } catch (RestoreRefused) {
        }
        for ($i = 2; $i <= RestorePointRules::LIMIT; ++$i) {
            $points->pose($this->dossier, 'Point '.$i, $this->teacher, new \DateTimeImmutable(self::AT));
        }
        try {
            $points->pose($this->dossier, 'Point 11', $this->teacher, new \DateTimeImmutable(self::AT));
            self::fail('Dix points au plus.');
        } catch (RestoreRefused $refused) {
            self::assertSame(RestorePointRules::LIMIT_REACHED, $refused->getMessage());
        }
        $this->runner()->preparePoint((int) $preparing->getId(), new \DateTimeImmutable(self::AT));
        $ready = $this->reload($preparing);
        $restorations = self::getContainer()->get(Restorations::class);
        try {
            $restorations->returnTo($ready, true, $this->teacher, new \DateTimeImmutable(self::AT));
            self::fail('Garder l’état actuel demande une place.');
        } catch (RestoreRefused $refused) {
            self::assertSame('keep', $refused->field);
        }
        $restorations->returnTo($ready, false, $this->teacher, new \DateTimeImmutable(self::AT));
        foreach ([
            fn () => $restorations->returnTo($ready, false, $this->teacher, new \DateTimeImmutable(self::AT)),
            fn () => $points->delete($ready, $this->teacher),
        ] as $gesture) {
            try {
                $gesture();
                self::fail('Rien pendant un retour.');
            } catch (RestoreRefused) {
            }
        }
    }

    public function testDeletingAPointOrItsDossierDeletesTheCopies(): void
    {
        $first = $this->readyPoint('Premier');
        $second = $this->readyPoint('Second');
        $connection = self::getContainer()->get(Connection::class);
        $firstCopy = (int) $first->getSnapshot()->getId();
        $secondCopy = (int) $second->getSnapshot()->getId();

        $this->points()->delete($first, $this->teacher);
        self::assertSame(0, self::rowCount($connection->fetchOne('SELECT COUNT(*) FROM dossier WHERE id = ?', [$firstCopy])));
        self::assertSame(0, self::rowCount($connection->fetchOne('SELECT COUNT(*) FROM entry WHERE dossier_id = ?', [$firstCopy])));
        self::assertSame([], $this->files()->keys($firstCopy));
        self::assertSame(1, $this->trailCount(AuditAction::RestorePointDeleted));

        $id = $this->dossierId();
        self::getContainer()->get(DossierEraser::class)->deleteDossier($this->entityManager()->find(Dossier::class, $id) ?? $this->dossier);
        self::assertSame(0, self::rowCount($connection->fetchOne('SELECT COUNT(*) FROM dossier WHERE id IN (?, ?)', [$id, $secondCopy])));
        self::assertSame(0, self::rowCount($connection->fetchOne('SELECT COUNT(*) FROM restore_point WHERE dossier_id = ?', [$id])));
    }

    public function testTheScheduledCommandFinishesWhatARequestLeft(): void
    {
        $point = $this->points()->pose($this->dossier, 'Laissé en route', $this->teacher, new \DateTimeImmutable(self::AT));
        self::getContainer()->get(RestoreQueue::class)->reset();

        $tester = new CommandTester((new Application(self::$kernel ?? throw new \LogicException('Booted.')))->find('app:restore-points:advance'));
        self::assertSame(0, $tester->execute([]));

        self::assertStringContainsString('1 point(s) de restauration préparé(s)', $tester->getDisplay());
        self::assertTrue($this->reload($point)->isReady());
    }

    private function readyPoint(string $name): RestorePoint
    {
        $point = $this->points()->pose($this->dossier, $name, $this->teacher, new \DateTimeImmutable(self::AT));
        self::assertTrue($this->runner()->preparePoint((int) $point->getId(), new \DateTimeImmutable(self::AT)));

        return $this->reload($point);
    }

    private function reload(RestorePoint $point): RestorePoint
    {
        $entityManager = $this->entityManager();
        $fresh = $entityManager->find(RestorePoint::class, $point->getId()) ?? throw new \LogicException('The point is there.');
        $entityManager->refresh($fresh);
        $dossier = $entityManager->find(Dossier::class, $this->dossierId());
        if (null !== $dossier) {
            $this->dossier = $dossier;
        }
        $teacher = $entityManager->find(User::class, $this->teacher->getId());
        if (null !== $teacher) {
            $this->teacher = $teacher;
        }

        return $fresh;
    }

    private function firstValidated(): Entry
    {
        return $this->within(function (): Entry {
            $purchases = self::getContainer()->get(JournalRepository::class)->findOneByCode($this->dossier, 'ACH') ?? throw new \LogicException('ACH.');
            $january = self::getContainer()->get(PeriodRepository::class)->findAt($this->dossier, new \DateTimeImmutable('2026-01-15')) ?? throw new \LogicException('January.');

            return array_values(array_filter(self::getContainer()->get(EntryRepository::class)->findInJournalPeriod($purchases, $january), static fn (Entry $entry): bool => EntryState::Validated === $entry->getState()))[0];
        });
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function within(callable $work): mixed
    {
        return self::getContainer()->get(DossierContext::class)->within($this->dossier, $work);
    }

    private function trailCount(AuditAction $action): int
    {
        return self::rowCount(self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM audit_event WHERE dossier_id = ? AND action = ?', [$this->dossierId(), $action->value]));
    }

    private static function rowCount(mixed $value): int
    {
        return \is_int($value) || (\is_string($value) && ctype_digit($value)) ? (int) $value : throw new \UnexpectedValueException('A count.');
    }

    private function dossierId(): int
    {
        return $this->dossier->getId() ?? throw new \LogicException('Saved.');
    }

    private function points(): RestorePoints
    {
        return self::getContainer()->get(RestorePoints::class);
    }

    private function runner(): RestoreRunner
    {
        return self::getContainer()->get(RestoreRunner::class);
    }

    private function files(): DossierFiles
    {
        return self::getContainer()->get(DossierFiles::class);
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function export(): CanonicalDossierExport
    {
        return new CanonicalDossierExport(self::getContainer()->get(Connection::class), self::getContainer()->get(CopyPlan::class), self::getContainer()->get(DossierCopier::class));
    }
}
