<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Identity\SchoolClass;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Enum\Identity\AccountKind;
use App\Enum\Identity\ClassLevel;
use App\Enum\Identity\EntryBasis;
use App\Security\Voter\SchoolClassVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/** Every teacher reads every class and places students in the classes of the current year or a later one; a past year is read only; a student gets nothing. */
final class SchoolClassVoterTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private SchoolClassVoter $voter;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->voter = self::getContainer()->get(SchoolClassVoter::class);
    }

    public function testATeacherReadsAndPlacesInTheCurrentAndLaterYears(): void
    {
        [$past, $current, $next] = $this->years();
        $teacher = $this->account(AccountKind::Teacher, 'teacher');

        foreach ([$current, $next] as $class) {
            self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($teacher, SchoolClassVoter::VIEW, $class));
            self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($teacher, SchoolClassVoter::PLACE, $class));
        }
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($teacher, SchoolClassVoter::VIEW, $past));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($teacher, SchoolClassVoter::PLACE, $past));
    }

    public function testAStudentGetsNothing(): void
    {
        [, $current] = $this->years();
        $student = $this->account(AccountKind::Student, 'student');

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($student, SchoolClassVoter::VIEW, $current));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($student, SchoolClassVoter::PLACE, $current));
    }

    public function testOtherSubjectsAndAttributesAbstain(): void
    {
        [, $current] = $this->years();
        $teacher = $this->account(AccountKind::Teacher, 'teacher');

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($teacher, 'CLASS_DELETE', $current));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($teacher, SchoolClassVoter::VIEW, $teacher));
    }

    /** @return array{SchoolClass, SchoolClass, SchoolClass} a class of last year, of the current one, of the next one */
    private function years(): array
    {
        $at = new \DateTimeImmutable('2026-11-27');
        $classes = [];
        foreach ([2025, 2026, 2027] as $start) {
            $year = new SchoolYear(new \DateTimeImmutable($start.'-09-01'), new \DateTimeImmutable(($start + 1).'-08-31'), $at);
            if (2026 === $start) {
                $year->markCurrent();
            }
            $this->entityManager->persist($year);
            $class = new SchoolClass($year, 'BTS CG 1', ClassLevel::First, $at);
            $this->entityManager->persist($class);
            $classes[] = $class;
        }
        $this->entityManager->flush();

        return [$classes[0], $classes[1], $classes[2]];
    }

    private function account(AccountKind $kind, string $login): User
    {
        $user = new User('uidNumber:'.$login, $login, ucfirst($login), $kind, AccountKind::Teacher === $kind ? EntryBasis::TeacherGroup : EntryBasis::StudentGroup, [], new \DateTimeImmutable('2026-11-27'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function vote(User $user, string $attribute, mixed $subject): int
    {
        return $this->voter->vote(new UsernamePasswordToken($user, 'main', $user->getRoles()), $subject, [$attribute]);
    }
}
