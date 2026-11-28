<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Identity\User;
use App\Entity\Mission\Mission;
use App\Enum\Accounting\AccountingBehaviour;
use App\Enum\Accounting\AssistanceLevel;
use App\Enum\Dossier\DossierKind;
use App\Enum\Identity\AccountKind;
use App\Enum\Identity\EntryBasis;
use App\Enum\Mission\MissionModule;
use App\Security\Voter\MissionVoter;
use App\Tests\Support\SavesDossiers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/** A mission is its author's (step 2.9a): a colleague, an administrator and a student answer no. */
final class MissionVoterTest extends KernelTestCase
{
    use SavesDossiers;

    public function testOnlyItsAuthorOpensAMission(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $at = new \DateTimeImmutable('2026-11-28');
        $author = $this->account(AccountKind::Teacher, 'durand');
        $colleague = $this->account(AccountKind::Teacher, 'leroy');
        $administrator = $this->account(AccountKind::Teacher, 'martin');
        $administrator->makeAdministrator($at, null);
        $student = $this->account(AccountKind::Student, 'manon');
        $mission = new Mission($this->savedDossier(DossierKind::Template, $author), $author, 'Janvier', MissionModule::cases(), AssistanceLevel::Warnings, AccountingBehaviour::LetEnter, $at);
        $entityManager->persist($mission);
        $entityManager->flush();

        self::assertTrue($this->grants($author, $mission));
        self::assertFalse($this->grants($colleague, $mission));
        self::assertFalse($this->grants($administrator, $mission), 'Pas de passe-droit : la mission porte ce que les étudiants feront.');
        self::assertFalse($this->grants($student, $mission));
    }

    private function grants(User $user, Mission $mission): bool
    {
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        return VoterInterface::ACCESS_GRANTED === self::getContainer()->get(MissionVoter::class)->vote($token, $mission, [MissionVoter::EDIT]);
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
