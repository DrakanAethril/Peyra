<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierMember;
use App\Entity\Dossier\DossierShare;
use App\Entity\Identity\Enrolment;
use App\Entity\Identity\SchoolClass;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\TeacherClass;
use App\Entity\Identity\User;
use App\Enum\Dossier\DossierKind;
use App\Enum\Identity\AccountKind;
use App\Enum\Identity\ClassLevel;
use App\Enum\Identity\EnrolmentOrigin;
use App\Enum\Identity\EntryBasis;
use App\Security\Voter\DossierVoter;
use App\Tests\Support\SavesDossiers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Who enters a dossier (lot 1, step 1.1): the student or the group's members; the teachers of a
 * class of theirs this year, every year's dossiers; the teacher who distributed it, reading; a
 * template's author and its shares; the common library for every teacher, changed by the
 * administrators; a mission's answer key copy for its teacher alone (step 2.9b); nobody else -
 * administrators included - and nobody into a restore point.
 */
final class DossierVoterTest extends KernelTestCase
{
    use SavesDossiers;
    private const array ALL = [DossierVoter::VIEW, DossierVoter::EDIT, DossierVoter::SET_DATE];

    private EntityManagerInterface $entityManager;
    private DossierVoter $voter;
    private SchoolYear $lastYear;
    private SchoolYear $thisYear;
    private SchoolClass $cg1;
    private SchoolClass $cg2;
    private SchoolClass $lastCg1;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->voter = self::getContainer()->get(DossierVoter::class);
        $at = new \DateTimeImmutable('2026-01-01');
        $this->lastYear = new SchoolYear(new \DateTimeImmutable('2025-09-01'), new \DateTimeImmutable('2026-08-31'), $at);
        $this->thisYear = new SchoolYear(new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2027-08-31'), $at);
        $this->thisYear->markCurrent();
        $this->lastCg1 = new SchoolClass($this->lastYear, 'BTS CG 1', ClassLevel::First, $at);
        $this->cg1 = new SchoolClass($this->thisYear, 'BTS CG 1', ClassLevel::First, $at);
        $this->cg2 = new SchoolClass($this->thisYear, 'BTS CG 2', ClassLevel::Second, $at);
        foreach ([$this->lastYear, $this->thisYear, $this->lastCg1, $this->cg1, $this->cg2] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
    }

    public function testAStudentWorksInTheirOwnDossierButNeverMovesItsDate(): void
    {
        $manon = $this->account(AccountKind::Student, 'manon');
        $lucas = $this->account(AccountKind::Student, 'lucas');
        $dossier = $this->dossier(DossierKind::Student, $manon);

        self::assertSame([true, true, false], $this->votes($manon, $dossier));
        self::assertSame([false, false, false], $this->votes($lucas, $dossier));
    }

    public function testTheTeachersOfTheStudentsClassThisYearReadEveryYearsDossiers(): void
    {
        $manon = $this->account(AccountKind::Student, 'manon');
        $this->enrol($manon, $this->lastCg1);
        $this->enrol($manon, $this->cg2);
        $lastYearsDossier = $this->dossier(DossierKind::Student, $manon, $this->lastYear);
        $teacherNow = $this->teacherOf($this->cg2, 'durand');
        $teacherBefore = $this->teacherOf($this->lastCg1, 'leroy');
        $teacherElsewhere = $this->teacherOf($this->cg1, 'martin');

        self::assertSame([true, true, true], $this->votes($teacherNow, $lastYearsDossier));
        self::assertSame([false, false, false], $this->votes($teacherBefore, $lastYearsDossier), 'Un enseignant qui ne l’a plus perd la lecture de ses dossiers.');
        self::assertSame([false, false, false], $this->votes($teacherElsewhere, $lastYearsDossier));
    }

    public function testTheDistributingTeacherKeepsReading(): void
    {
        $manon = $this->account(AccountKind::Student, 'manon');
        $this->enrol($manon, $this->cg2);
        $before = $this->teacherOf($this->lastCg1, 'leroy');
        $template = $this->dossier(DossierKind::Template, $before);
        $dossier = $this->dossier(DossierKind::Student, $manon);
        $dossier->comesFrom($template, $before);

        self::assertSame([true, false, false], $this->votes($before, $dossier));
    }

    public function testAnAdministratorHasNoWayIntoAStudentsDossierOutsideTheirClasses(): void
    {
        $manon = $this->account(AccountKind::Student, 'manon');
        $this->enrol($manon, $this->cg1);
        $admin = $this->account(AccountKind::Teacher, 'admin');
        $admin->makeAdministrator(new \DateTimeImmutable('2026-01-01'), null);
        $this->entityManager->flush();

        self::assertSame([false, false, false], $this->votes($admin, $this->dossier(DossierKind::Student, $manon)));
    }

    public function testAGroupsMembersAndTheirTeachers(): void
    {
        $manon = $this->account(AccountKind::Student, 'manon');
        $lucas = $this->account(AccountKind::Student, 'lucas');
        $theo = $this->account(AccountKind::Student, 'theo');
        $this->enrol($lucas, $this->cg1);
        $group = $this->dossier(DossierKind::Group, null);
        foreach ([$manon, $lucas] as $member) {
            $this->entityManager->persist(new DossierMember($group, $member, new \DateTimeImmutable('2026-01-01')));
        }
        $this->entityManager->flush();
        $teacher = $this->teacherOf($this->cg1, 'durand');

        self::assertSame([true, true, false], $this->votes($manon, $group));
        self::assertSame([true, true, false], $this->votes($lucas, $group));
        self::assertSame([false, false, false], $this->votes($theo, $group));
        self::assertSame([true, true, true], $this->votes($teacher, $group));
    }

    public function testATemplatesAuthorAndItsShares(): void
    {
        $author = $this->account(AccountKind::Teacher, 'author');
        $reader = $this->account(AccountKind::Teacher, 'reader');
        $editor = $this->account(AccountKind::Teacher, 'editor');
        $stranger = $this->account(AccountKind::Teacher, 'stranger');
        $student = $this->account(AccountKind::Student, 'manon');
        $template = $this->dossier(DossierKind::Template, $author);
        $this->entityManager->persist(new DossierShare($template, $reader, false, new \DateTimeImmutable('2026-01-01')));
        $this->entityManager->persist(new DossierShare($template, $editor, true, new \DateTimeImmutable('2026-01-01')));
        $this->entityManager->flush();

        self::assertSame([true, true, true], $this->votes($author, $template));
        self::assertSame([true, false, false], $this->votes($reader, $template));
        self::assertSame([true, true, true], $this->votes($editor, $template));
        self::assertSame([false, false, false], $this->votes($stranger, $template));
        self::assertSame([false, false, false], $this->votes($student, $template));
    }

    public function testTheCommonLibraryIsReadByEveryTeacherAndChangedByTheAdministrators(): void
    {
        $teacher = $this->account(AccountKind::Teacher, 'teacher');
        $admin = $this->account(AccountKind::Teacher, 'admin');
        $admin->makeAdministrator(new \DateTimeImmutable('2026-01-01'), null);
        $student = $this->account(AccountKind::Student, 'manon');
        $common = $this->dossier(DossierKind::Template, null);

        self::assertSame([true, false, false], $this->votes($teacher, $common));
        self::assertSame([true, true, true], $this->votes($admin, $common));
        self::assertSame([false, false, false], $this->votes($student, $common));
    }

    public function testARestorePointIsNeverOpened(): void
    {
        $admin = $this->account(AccountKind::Teacher, 'admin');
        $admin->makeAdministrator(new \DateTimeImmutable('2026-01-01'), null);

        self::assertSame([false, false, false], $this->votes($admin, $this->dossier(DossierKind::Snapshot, null)));
    }

    public function testAMissionsAnswerKeyCopyIsItsTeachersAlone(): void
    {
        $author = $this->account(AccountKind::Teacher, 'author');
        $admin = $this->account(AccountKind::Teacher, 'admin');
        $admin->makeAdministrator(new \DateTimeImmutable('2026-01-01'), null);
        $student = $this->account(AccountKind::Student, 'manon');
        $copy = $this->dossier(DossierKind::AnswerKey, $author);

        self::assertSame([true, true, true], $this->votes($author, $copy));
        self::assertSame([false, false, false], $this->votes($admin, $copy));
        self::assertSame([false, false, false], $this->votes($student, $copy));
    }

    public function testOtherSubjectsAndAttributesAbstain(): void
    {
        $manon = $this->account(AccountKind::Student, 'manon');
        $dossier = $this->dossier(DossierKind::Student, $manon);
        $token = new UsernamePasswordToken($manon, 'main', $manon->getRoles());

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($token, $dossier, ['DOSSIER_DELETE']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($token, $manon, [DossierVoter::VIEW]));
    }

    /** @return list<bool> VIEW, EDIT, SET_DATE */
    private function votes(User $user, Dossier $dossier): array
    {
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        return array_map(fn (string $attribute): bool => VoterInterface::ACCESS_GRANTED === $this->voter->vote($token, $dossier, [$attribute]), self::ALL);
    }

    private function dossier(DossierKind $kind, ?User $owner, ?SchoolYear $year = null): Dossier
    {
        return $this->savedDossier($kind, $owner, $year ?? $this->thisYear);
    }

    private function account(AccountKind $kind, string $login): User
    {
        $student = AccountKind::Student === $kind;
        $user = new User('uid:'.$login, $login, ucfirst($login), $kind, $student ? EntryBasis::StudentGroup : EntryBasis::TeacherGroup, [$student ? 'student' : 'teacher', 'cg-1'], new \DateTimeImmutable('2026-01-01'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function enrol(User $student, SchoolClass $class): void
    {
        $this->entityManager->persist(new Enrolment($student, $class, EnrolmentOrigin::Manual, new \DateTimeImmutable('2026-01-01'), null));
        $this->entityManager->flush();
    }

    private function teacherOf(SchoolClass $class, string $login): User
    {
        $teacher = $this->account(AccountKind::Teacher, $login);
        $this->entityManager->persist(new TeacherClass($teacher, $class, new \DateTimeImmutable('2026-01-01'), null));
        $this->entityManager->flush();

        return $teacher;
    }
}
