<?php

declare(strict_types=1);

namespace App\Tests\Service\Dossier\Distribution;

use App\Entity\Dossier\Distribution;
use App\Entity\Dossier\DistributionCopy;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\Enrolment;
use App\Entity\Identity\SchoolClass;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\TeacherClass;
use App\Entity\Identity\User;
use App\Enum\Audit\AuditAction;
use App\Enum\Dossier\DistributionCopyState;
use App\Enum\Dossier\DistributionMode;
use App\Enum\Dossier\DossierKind;
use App\Enum\Dossier\DossierProfile;
use App\Enum\Dossier\RestorePointOrigin;
use App\Enum\Identity\AccountKind;
use App\Enum\Identity\ClassLevel;
use App\Enum\Identity\EnrolmentOrigin;
use App\Enum\Identity\EntryBasis;
use App\Repository\Dossier\DistributionCopyRepository;
use App\Repository\Dossier\DossierMemberRepository;
use App\Repository\Dossier\RestorePointRepository;
use App\Service\Dossier\Demo\DemoInstaller;
use App\Service\Dossier\Distribution\DistributionChoices;
use App\Service\Dossier\Distribution\DistributionPlanner;
use App\Service\Dossier\Distribution\DistributionQueue;
use App\Service\Dossier\Distribution\DistributionRunner;
use App\Service\Dossier\Distribution\Distributions;
use App\Service\Dossier\Restore\RestorePoints;
use App\Service\Dossier\Restore\RestoreRefused;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Distributions (lot 1, step 1.8; design-ux.md § 4.2) of the demonstration template to a class:
 * each dossier is made whole - content, members, « État de départ » - or not at all, a failed one
 * is relaunched, the date of all of them moves in one gesture, and withdrawing deletes them.
 */
final class DistributionsTest extends KernelTestCase
{
    private const string AT = '2026-11-28 09:00';

    private Dossier $template;
    private SchoolClass $schoolClass;
    private User $teacher;

    /** @var list<User> */
    private array $students = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $at = new \DateTimeImmutable(self::AT);
        $installer = self::getContainer()->get(DemoInstaller::class);
        $installer->installTemplate('test', $at);
        $this->template = $installer->findTemplate() ?? throw new \LogicException('The demonstration is installed.');
        $entityManager = $this->entityManager();
        $year = new SchoolYear(new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2027-08-31'), $at);
        $year->markCurrent();
        $this->schoolClass = new SchoolClass($year, 'BTS CG 1', ClassLevel::First, $at);
        $this->teacher = new User('uid:distributeur', 'prof.distributeur', 'Prof Distributeur', AccountKind::Teacher, EntryBasis::TeacherGroup, ['teacher', 'cg-1'], $at);
        foreach ([$year, $this->schoolClass, $this->teacher, new TeacherClass($this->teacher, $this->schoolClass, $at, null)] as $entity) {
            $entityManager->persist($entity);
        }
        foreach (['Bernard Lucas', 'Chevalier Manon', 'Fabre Chloé', 'Morel Inès'] as $index => $name) {
            $student = new User('uid:distrib'.$index, 'distrib'.$index, $name, AccountKind::Student, EntryBasis::StudentGroup, ['student', 'cg-1'], $at);
            $entityManager->persist($student);
            $entityManager->persist(new Enrolment($student, $this->schoolClass, EnrolmentOrigin::Manual, $at, null));
            $this->students[] = $student;
        }
        $entityManager->flush();
    }

    public function testEachStudentGetsTheTemplateWithItsStartingPoint(): void
    {
        $distribution = $this->launch(DistributionChoices::first());
        self::assertSame([$distribution->getId()], self::getContainer()->get(DistributionQueue::class)->take());
        $copyIds = $this->copies()->findPendingIds($distribution);
        self::assertCount(4, $copyIds);

        foreach ($copyIds as $id) {
            self::assertTrue($this->runner()->make($id, new \DateTimeImmutable('2026-11-28 09:01')));
        }
        self::assertFalse($this->runner()->make($copyIds[0], new \DateTimeImmutable('2026-11-28 09:02')), 'Un dossier fait ne se refait pas.');

        $templateEntries = $this->rows('SELECT COUNT(*) FROM entry WHERE dossier_id = ?', $this->template->getId());
        self::assertGreaterThan(0, $templateEntries);
        foreach ($this->copiesOf($distribution) as $index => $copy) {
            self::assertSame(DistributionCopyState::Ready, $copy->getState());
            $dossier = $copy->getDossier() ?? throw new \LogicException('Made.');
            self::assertSame(DossierKind::Student, $dossier->getKind());
            self::assertSame($this->students[$index]->getId(), $dossier->getOwner()?->getId());
            self::assertSame($this->template->getId(), $dossier->getSourceTemplate()?->getId());
            self::assertSame($this->teacher->getId(), $dossier->getDistributedBy()?->getId());
            self::assertSame($this->schoolClass->getSchoolYear()->getId(), $dossier->getSchoolYear()?->getId());
            self::assertSame('2026-03-31', $dossier->getDossierDate()->format('Y-m-d'));
            self::assertSame($templateEntries, $this->rows('SELECT COUNT(*) FROM entry WHERE dossier_id = ?', $dossier->getId()));

            $points = self::getContainer()->get(RestorePointRepository::class)->findByDossier($dossier);
            self::assertCount(1, $points);
            self::assertSame(Distributions::START_POINT, $points[0]->getName());
            self::assertSame(RestorePointOrigin::Start, $points[0]->getOrigin());
            self::assertTrue($points[0]->isReady());
            self::assertSame($templateEntries, $this->rows('SELECT COUNT(*) FROM entry WHERE dossier_id = ?', $points[0]->getSnapshot()->getId()));
            self::assertSame(0, self::getContainer()->get(RestorePointRepository::class)->countAgainstLimit($dossier), 'L’état de départ est hors de la limite des dix points.');
            self::assertSame(1, $this->rows('SELECT COUNT(*) FROM audit_event WHERE dossier_id = ? AND action = ?', $dossier->getId(), AuditAction::DossierCreated->value));
        }
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM audit_event WHERE subject_type = ? AND subject_id = ? AND action = ?', 'distribution', (string) $distribution->getId(), AuditAction::DistributionLaunched->value));

        // Those who hold a copy are no longer ticked by default.
        $again = self::getContainer()->get(DistributionPlanner::class)->plan($this->template, $this->schoolClass, DistributionChoices::first());
        self::assertSame([], $again->ticked());
        self::assertCount(4, array_filter($again->lines, static fn ($line): bool => $line->holds));
    }

    public function testTheStartingPointIsATeachersToDelete(): void
    {
        $distribution = $this->launch(DistributionChoices::first());
        $this->makeAll($distribution);
        $dossier = $this->copiesOf($distribution)[0]->getDossier() ?? throw new \LogicException('Made.');
        $point = self::getContainer()->get(RestorePointRepository::class)->findByDossier($dossier)[0];
        $student = $this->entityManager()->find(User::class, $this->students[0]->getId()) ?? throw new \LogicException('There.');

        try {
            self::getContainer()->get(RestorePoints::class)->delete($point, $student);
            self::fail('L’étudiant ne supprime pas l’état de départ.');
        } catch (RestoreRefused $refused) {
            self::assertStringContainsString('État de départ', $refused->getMessage());
        }
        self::getContainer()->get(RestorePoints::class)->delete($point, $this->reloadedTeacher());
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM restore_point WHERE id = ?', $point->getId()));
    }

    public function testAGroupGetsOneSharedDossierWithItsProfiles(): void
    {
        $ids = array_map(static fn (User $student): int => (int) $student->getId(), $this->students);
        $distribution = $this->launch(new DistributionChoices(
            $ids,
            DistributionMode::Group,
            [$ids[0] => 1, $ids[1] => 1, $ids[2] => 2, $ids[3] => 2],
            [$ids[0] => DossierProfile::SupplierAccountant, $ids[2] => DossierProfile::ChiefAccountant],
            '2026-02-28',
        ));
        $this->makeAll($distribution);

        $copies = $this->copiesOf($distribution);
        self::assertSame(['Groupe 1', 'Groupe 2'], array_map(static fn (DistributionCopy $copy): ?string => $copy->getGroupName(), $copies));
        $dossier = $copies[0]->getDossier() ?? throw new \LogicException('Made.');
        self::assertSame(DossierKind::Group, $dossier->getKind());
        self::assertNull($dossier->getOwner());
        self::assertSame('2026-02-28', $dossier->getDossierDate()->format('Y-m-d'));
        $members = self::getContainer()->get(DossierMemberRepository::class)->findByDossier($dossier);
        self::assertSame([$ids[0] => DossierProfile::SupplierAccountant, $ids[1] => null], array_combine(
            array_map(static fn ($member): int => (int) $member->getStudent()->getId(), $members),
            array_map(static fn ($member): ?DossierProfile => $member->getProfile(), $members),
        ));
    }

    public function testAFailedCopyLeavesNothingAndIsRelaunched(): void
    {
        $ids = array_map(static fn (User $student): int => (int) $student->getId(), $this->students);
        $distribution = $this->launch(new DistributionChoices([$ids[0], $ids[1]], DistributionMode::Group, [], [], ''));
        $copyId = $this->copies()->findPendingIds($distribution)[0];
        $connection = self::getContainer()->get(Connection::class);
        // A member who no longer exists: the copy stops after the dossier was made and copied.
        $connection->executeStatement('UPDATE distribution_copy SET members = ? WHERE id = ?', [json_encode([['student' => $ids[0], 'profile' => null], ['student' => 999999, 'profile' => null]]), $copyId]);
        $dossiers = $this->rows('SELECT COUNT(*) FROM dossier');

        self::assertTrue($this->runner()->make($copyId, new \DateTimeImmutable('2026-11-28 09:01')));

        $copy = $this->reloadCopy($copyId);
        self::assertSame(DistributionCopyState::Failed, $copy->getState());
        self::assertSame('Un membre du groupe n’existe plus : ce dossier ne peut plus être créé.', $copy->getFailure());
        self::assertNull($copy->getDossier());
        self::assertSame($dossiers, $this->rows('SELECT COUNT(*) FROM dossier'), 'Rien n’est gardé d’une copie qui s’arrête.');
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM audit_event WHERE subject_type = ? AND action = ?', 'distribution', AuditAction::DistributionCopyFailed->value));

        $connection->executeStatement('UPDATE distribution_copy SET members = ? WHERE id = ?', [json_encode([['student' => $ids[0], 'profile' => null], ['student' => $ids[1], 'profile' => null]]), $copyId]);
        $this->entityManager()->refresh($copy);
        self::getContainer()->get(Distributions::class)->retry($copy, $this->reloadedTeacher());
        self::assertSame(DistributionCopyState::Waiting, $this->reloadCopy($copyId)->getState());
        self::assertTrue($this->runner()->make($copyId, new \DateTimeImmutable('2026-11-28 09:02')));
        self::assertSame(DistributionCopyState::Ready, $this->reloadCopy($copyId)->getState());
    }

    public function testTheDateOfAllItsDossiersMovesAndWithdrawingDeletesThem(): void
    {
        $distribution = $this->launch(DistributionChoices::first());
        $this->makeAll($distribution);
        $distributions = self::getContainer()->get(Distributions::class);
        $first = $this->copiesOf($distribution)[0]->getDossier();

        $counts = $distributions->moveDate($this->reloadDistribution($distribution), new \DateTimeImmutable('2026-02-28'), $this->reloadedTeacher(), new \DateTimeImmutable(self::AT), static fn (Dossier $dossier): bool => $dossier !== $first);
        self::assertSame(['moved' => 3, 'unchanged' => 0, 'refused' => 1], $counts);
        $dates = array_map(static fn (DistributionCopy $copy): string => $copy->getDossier()?->getDossierDate()->format('Y-m-d') ?? '', $this->copiesOf($distribution));
        self::assertSame(['2026-03-31', '2026-02-28', '2026-02-28', '2026-02-28'], $dates);

        $dossierIds = array_map(static fn (DistributionCopy $copy): ?int => $copy->getDossier()?->getId(), $this->copiesOf($distribution));
        $distribution = $this->reloadDistribution($distribution);
        self::assertSame('Tapez « Vasseur, à-nouveaux 2026 » pour retirer cette distribution.', $distributions->withdraw($distribution, 'Vasseur', $this->reloadedTeacher()));
        self::assertNull($distributions->withdraw($distribution, $distribution->getTitle(), $this->reloadedTeacher()));

        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM dossier WHERE id IN ('.implode(',', array_map('intval', $dossierIds)).')'));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM distribution WHERE id = ?', $distribution->getId() ?? 0));
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM audit_event WHERE subject_type = ? AND action = ?', 'distribution', AuditAction::DistributionWithdrawn->value));
        self::assertGreaterThan(0, $this->rows('SELECT COUNT(*) FROM audit_event WHERE dossier_id = ?', $dossierIds[0]), 'La piste d’un dossier retiré reste.');
    }

    public function testTheScheduledCommandMakesWhatARequestLeft(): void
    {
        $distribution = $this->launch(DistributionChoices::first());
        self::getContainer()->get(DistributionQueue::class)->reset();

        $tester = new CommandTester((new Application(self::$kernel ?? throw new \LogicException('Booted.')))->find('app:distribution:advance'));
        self::assertSame(0, $tester->execute([]));

        self::assertStringContainsString('4 dossier(s) de distribution traité(s)', $tester->getDisplay());
        self::assertSame([], $this->copies()->findPendingIds($distribution));
    }

    private function launch(DistributionChoices $choices): Distribution
    {
        $plan = self::getContainer()->get(DistributionPlanner::class)->plan($this->template, $this->schoolClass, $choices);

        return self::getContainer()->get(Distributions::class)->launch($plan, $this->teacher, new \DateTimeImmutable(self::AT));
    }

    private function makeAll(Distribution $distribution): void
    {
        foreach ($this->copies()->findPendingIds($distribution) as $id) {
            self::assertTrue($this->runner()->make($id, new \DateTimeImmutable('2026-11-28 09:01')));
        }
    }

    /** @return list<DistributionCopy> */
    private function copiesOf(Distribution $distribution): array
    {
        $this->entityManager()->clear();

        return $this->copies()->findByDistribution($this->reloadDistribution($distribution));
    }

    private function reloadDistribution(Distribution $distribution): Distribution
    {
        return $this->entityManager()->find(Distribution::class, $distribution->getId()) ?? throw new \LogicException('There.');
    }

    private function reloadCopy(int $id): DistributionCopy
    {
        $copy = $this->entityManager()->find(DistributionCopy::class, $id) ?? throw new \LogicException('There.');
        $this->entityManager()->refresh($copy);

        return $copy;
    }

    private function reloadedTeacher(): User
    {
        return $this->entityManager()->find(User::class, $this->teacher->getId()) ?? throw new \LogicException('There.');
    }

    private function rows(string $sql, mixed ...$parameters): int
    {
        $value = self::getContainer()->get(Connection::class)->fetchOne($sql, array_values($parameters));

        return \is_int($value) || (\is_string($value) && ctype_digit($value)) ? (int) $value : throw new \UnexpectedValueException('A count.');
    }

    private function copies(): DistributionCopyRepository
    {
        return self::getContainer()->get(DistributionCopyRepository::class);
    }

    private function runner(): DistributionRunner
    {
        return self::getContainer()->get(DistributionRunner::class);
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
