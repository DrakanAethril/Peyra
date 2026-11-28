<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierShare;
use App\Entity\Identity\User;
use App\Enum\Dossier\DossierKind;
use App\Enum\Identity\AccountKind;
use App\Enum\Identity\EntryBasis;
use App\Security\Voter\TemplateVoter;
use App\Tests\Support\SavesDossiers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Share, delete, duplicate a template (lot 1, step 1.8a): the author shares and deletes; a
 * colleague it is shared with - even « en modification » - neither shares nor deletes it, and
 * duplicates it; the common library is shared by nobody and deleted by an administrator; anything
 * that is not a template answers no.
 */
final class TemplateVoterTest extends KernelTestCase
{
    use SavesDossiers;
    private const array ALL = [TemplateVoter::SHARE, TemplateVoter::DELETE, TemplateVoter::DUPLICATE];

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testTheAuthorSharesDeletesAndDuplicates(): void
    {
        $durand = $this->account(AccountKind::Teacher, 'durand');
        $template = $this->savedDossier(DossierKind::Template, $durand);

        self::assertSame([true, true, true], $this->votes($durand, $template));
    }

    public function testAColleagueItIsSharedWithDuplicatesItOnly(): void
    {
        $durand = $this->account(AccountKind::Teacher, 'durand');
        $reader = $this->account(AccountKind::Teacher, 'lecteur');
        $editor = $this->account(AccountKind::Teacher, 'redacteur');
        $other = $this->account(AccountKind::Teacher, 'autre');
        $template = $this->savedDossier(DossierKind::Template, $durand);
        $this->entityManager->persist(new DossierShare($template, $reader, false, new \DateTimeImmutable('2026-11-28')));
        $this->entityManager->persist(new DossierShare($template, $editor, true, new \DateTimeImmutable('2026-11-28')));
        $this->entityManager->flush();

        self::assertSame([false, false, true], $this->votes($reader, $template));
        self::assertSame([false, false, true], $this->votes($editor, $template));
        self::assertSame([false, false, false], $this->votes($other, $template), 'Un modèle qui ne lui est pas partagé ne lui est pas ouvert.');
    }

    public function testTheCommonLibraryIsDeletedByAnAdministratorAndSharedByNobody(): void
    {
        $teacher = $this->account(AccountKind::Teacher, 'durand');
        $administrator = $this->account(AccountKind::Teacher, 'martin');
        $administrator->makeAdministrator(new \DateTimeImmutable('2026-01-01'), null);
        $this->entityManager->flush();
        $common = $this->savedDossier(DossierKind::Template, null);

        self::assertSame([false, false, true], $this->votes($teacher, $common));
        self::assertSame([false, true, true], $this->votes($administrator, $common));
    }

    public function testNothingButATemplateAndNobodyButATeacher(): void
    {
        $student = $this->account(AccountKind::Student, 'manon');
        $teacher = $this->account(AccountKind::Teacher, 'durand');
        $administrator = $this->account(AccountKind::Teacher, 'martin');
        $administrator->makeAdministrator(new \DateTimeImmutable('2026-01-01'), null);
        $this->entityManager->flush();

        self::assertSame([false, false, false], $this->votes($administrator, $this->savedDossier(DossierKind::Student, $student)));
        self::assertSame([false, false, false], $this->votes($teacher, $this->savedDossier(DossierKind::Snapshot, null)));
        self::assertSame([false, false, false], $this->votes($student, $this->savedDossier(DossierKind::Template, null)));
    }

    /** @return list<bool> */
    private function votes(User $user, Dossier $dossier): array
    {
        $voter = self::getContainer()->get(TemplateVoter::class);
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        return array_map(static fn (string $attribute): bool => VoterInterface::ACCESS_GRANTED === $voter->vote($token, $dossier, [$attribute]), self::ALL);
    }

    private function account(AccountKind $kind, string $login): User
    {
        $student = AccountKind::Student === $kind;
        $user = new User('uid:'.$login, $login, ucfirst($login), $kind, $student ? EntryBasis::StudentGroup : EntryBasis::TeacherGroup, [$student ? 'student' : 'teacher', 'cg-1'], new \DateTimeImmutable('2026-01-01'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
