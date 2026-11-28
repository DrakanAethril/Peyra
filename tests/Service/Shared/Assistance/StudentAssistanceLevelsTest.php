<?php

declare(strict_types=1);

namespace App\Tests\Service\Shared\Assistance;

use App\Entity\Identity\User;
use App\Enum\Accounting\AssistanceLevel;
use App\Enum\Dossier\DossierKind;
use App\Enum\Identity\AccountKind;
use App\Service\Shared\Assistance\StudentAssistanceLevels;
use App\Tests\Support\BuildsGroupDossier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Whose own level a dossier is worked at (step 2.8): a student's dossier, its student's, whoever
 * opens it; a group's, the member working in it; a template, nobody's - « Avertissements » by default.
 */
final class StudentAssistanceLevelsTest extends KernelTestCase
{
    use BuildsGroupDossier;

    public function testTheLevelFollowsTheStudentWhoWorks(): void
    {
        self::bootKernel();
        [$group, $people] = $this->groupDossier();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $people['ines']->setAssistanceLevel(AssistanceLevel::Guided);
        $people['theo']->setAssistanceLevel(AssistanceLevel::None);
        $own = $this->savedDossier(DossierKind::Student, $people['theo']);
        $template = $this->savedDossier(DossierKind::Template, $people['durand']);
        $entityManager->flush();
        $levels = self::getContainer()->get(StudentAssistanceLevels::class);

        $this->signIn($people['ines']);
        self::assertSame(AssistanceLevel::Guided, $levels->for($group));
        self::assertSame(AssistanceLevel::None, $levels->for($own), 'Le dossier de Théo, à son niveau, qui que ce soit qui l’ouvre.');

        $this->signIn($people['chloe']);
        self::assertSame(AssistanceLevel::Warnings, $levels->for($group), 'Chloé n’a pas de niveau propre.');

        $this->signIn($people['durand']);
        self::assertSame(AssistanceLevel::Warnings, $levels->for($group), 'Un enseignant dans un dossier de groupe : le niveau par défaut.');
        self::assertSame(AssistanceLevel::None, $levels->for($own));
        self::assertSame(AssistanceLevel::Warnings, $levels->for($template));

        $outsider = $this->groupAccount($entityManager, AccountKind::Student, 'lucas');
        $outsider->setAssistanceLevel(AssistanceLevel::Blocking);
        $entityManager->flush();
        $this->signIn($outsider);
        self::assertSame(AssistanceLevel::Warnings, $levels->for($group), 'Hors du groupe, son niveau ne compte pas.');
    }

    private function signIn(User $user): void
    {
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }
}
