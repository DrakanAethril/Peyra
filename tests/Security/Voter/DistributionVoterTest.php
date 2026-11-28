<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Dossier\Distribution;
use App\Entity\Identity\SchoolClass;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Enum\Dossier\DistributionMode;
use App\Enum\Dossier\DossierKind;
use App\Enum\Identity\AccountKind;
use App\Enum\Identity\ClassLevel;
use App\Enum\Identity\EntryBasis;
use App\Security\Voter\DistributionVoter;
use App\Tests\Support\SavesDossiers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * A distribution is its author's (step 1.8c): a colleague, an administrator, a student and a
 * distribution made by a command answer no.
 */
final class DistributionVoterTest extends KernelTestCase
{
    use SavesDossiers;

    public function testOnlyItsAuthorManagesADistribution(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $at = new \DateTimeImmutable('2026-11-28');
        $year = new SchoolYear(new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2027-08-31'), $at);
        $class = new SchoolClass($year, 'BTS CG 1', ClassLevel::First, $at);
        $entityManager->persist($year);
        $entityManager->persist($class);
        $author = $this->account(AccountKind::Teacher, 'durand');
        $colleague = $this->account(AccountKind::Teacher, 'leroy');
        $administrator = $this->account(AccountKind::Teacher, 'martin');
        $administrator->makeAdministrator($at, null);
        $student = $this->account(AccountKind::Student, 'manon');
        $template = $this->savedDossier(DossierKind::Template, $author);
        $mine = new Distribution($template, $author, $class, DistributionMode::Individual, $at, $at);
        $byCommand = new Distribution($template, null, $class, DistributionMode::Individual, $at, $at);
        $entityManager->persist($mine);
        $entityManager->persist($byCommand);
        $entityManager->flush();

        self::assertTrue($this->grants($author, $mine));
        self::assertFalse($this->grants($colleague, $mine));
        self::assertFalse($this->grants($administrator, $mine), 'Pas de passe-droit : retirer supprime des dossiers d’étudiants.');
        self::assertFalse($this->grants($student, $mine));
        self::assertFalse($this->grants($administrator, $byCommand));
    }

    private function grants(User $user, Distribution $distribution): bool
    {
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        return VoterInterface::ACCESS_GRANTED === self::getContainer()->get(DistributionVoter::class)->vote($token, $distribution, [DistributionVoter::MANAGE]);
    }

    private function account(AccountKind $kind, string $login): User
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $student = AccountKind::Student === $kind;
        $user = new User('uid:'.$login, $login, ucfirst($login), $kind, $student ? EntryBasis::StudentGroup : EntryBasis::TeacherGroup, [$student ? 'student' : 'teacher', 'cg-1'], new \DateTimeImmutable('2026-01-01'));
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }
}
