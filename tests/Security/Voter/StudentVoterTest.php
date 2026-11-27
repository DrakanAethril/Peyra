<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Security\Voter\StudentVoter;
use App\Tests\Support\IdentityFixtures as F;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/** Every teacher opens a student's record and changes their class; nobody opens a teacher's; a student opens none. */
final class StudentVoterTest extends TestCase
{
    public function testATeacherOpensAStudentsRecord(): void
    {
        $voter = new StudentVoter();
        $teacher = F::teacher(1, 'Sophie Durand');
        $student = F::student(2, 'Inès Morel');
        $token = new UsernamePasswordToken($teacher, 'main', $teacher->getRoles());

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $student, [StudentVoter::VIEW]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $student, [StudentVoter::PLACE]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, F::teacher(3, 'Jean-Pierre Martin'), [StudentVoter::VIEW]));
    }

    public function testAStudentOpensNoRecord(): void
    {
        $voter = new StudentVoter();
        $student = F::student(2, 'Inès Morel');
        $token = new UsernamePasswordToken($student, 'main', $student->getRoles());

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $student, [StudentVoter::VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, F::student(4, 'Lucas Bernard'), [StudentVoter::PLACE]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, $student, ['STUDENT_DELETE']));
    }
}
