<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Accounting\FecImportJob;
use App\Entity\Identity\User;
use App\Enum\Dossier\DossierKind;
use App\Enum\Identity\AccountKind;
use App\Enum\Identity\EntryBasis;
use App\Security\Voter\DossierVoter;
use App\Security\Voter\FecImportVoter;
use App\Tests\Support\SavesDossiers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * A FEC import is its author's (step 1.10b): a colleague, an administrator and a student answer
 * no; and the template it fills is nobody's to open until its last pass, its author's included.
 */
final class FecImportVoterTest extends KernelTestCase
{
    use SavesDossiers;

    public function testOnlyItsAuthorFollowsAnImportAndNobodyOpensItsTemplateBeforeTheEnd(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $at = new \DateTimeImmutable('2026-11-28');
        $author = $this->account(AccountKind::Teacher, 'durand');
        $colleague = $this->account(AccountKind::Teacher, 'leroy');
        $administrator = $this->account(AccountKind::Teacher, 'martin');
        $administrator->makeAdministrator($at, null);
        $student = $this->account(AccountKind::Student, 'manon');
        $template = $this->savedDossier(DossierKind::Template, $author);
        self::assertTrue($this->opens($author, $template));
        $template->markImporting();
        $job = new FecImportJob($author, $template, 'FEC.txt', "JournalCode\n", new \DateTimeImmutable('2026-01-01'), 1, 1, 0, 0, 0, 0, $at);
        $entityManager->persist($job);
        $entityManager->flush();

        self::assertTrue($this->grants($author, $job));
        self::assertFalse($this->grants($colleague, $job));
        self::assertFalse($this->grants($administrator, $job), 'Le modèle est celui de son auteur.');
        self::assertFalse($this->grants($student, $job));

        self::assertFalse($this->opens($author, $template), 'Caché tant que le FEC s’écrit.');
        $template->markImported();
        self::assertTrue($this->opens($author, $template));
    }

    private function grants(User $user, FecImportJob $job): bool
    {
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        return VoterInterface::ACCESS_GRANTED === self::getContainer()->get(FecImportVoter::class)->vote($token, $job, [FecImportVoter::MANAGE]);
    }

    private function opens(User $user, \App\Entity\Dossier\Dossier $template): bool
    {
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        return VoterInterface::ACCESS_GRANTED === self::getContainer()->get(DossierVoter::class)->vote($token, $template, [DossierVoter::VIEW]);
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
