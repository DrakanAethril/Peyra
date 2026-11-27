<?php

declare(strict_types=1);

namespace App\Tests\Service\Identity;

use App\Entity\Identity\User;
use App\Enum\Identity\AccountKind;
use App\Enum\Identity\EntryBasis;
use App\Service\Identity\Administrators;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The rules of « Administrateurs » (design-ux.md § 3.6): only a teacher, never a bootstrap
 * administrator removed here, never the last one. PEYRA_ADMIN_LOGINS is `prof.martin` in the test
 * environment, as in .env.dev.local.example.
 */
final class AdministratorsTest extends KernelTestCase
{
    private const string AT = '2026-11-27 09:00';

    private Administrators $administrators;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->administrators = self::getContainer()->get(Administrators::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testTheLastAdministratorStays(): void
    {
        $durand = $this->teacher('prof.durand', 'Sophie Durand');
        $durand->makeAdministrator(new \DateTimeImmutable(self::AT), null);
        $this->entityManager->flush();

        self::assertSame('Le dernier administrateur ne se retire pas.', $this->administrators->revoke($durand, $durand, new \DateTimeImmutable(self::AT)));
        self::assertTrue($durand->isAdministrator());
    }

    public function testABootstrapAdministratorIsNotRemovedHere(): void
    {
        $martin = $this->teacher('prof.martin', 'Jean-Pierre Martin');
        $martin->makeAdministrator(new \DateTimeImmutable(self::AT), null);
        $durand = $this->teacher('prof.durand', 'Sophie Durand');
        self::assertNull($this->administrators->designate($durand, $martin, new \DateTimeImmutable(self::AT)));

        self::assertSame('Administrateur de la liste d’amorçage : il se retire dans la configuration du serveur, pas ici.', $this->administrators->revoke($martin, $durand, new \DateTimeImmutable(self::AT)));
        self::assertNull($this->administrators->revoke($durand, $martin, new \DateTimeImmutable(self::AT)));
        self::assertFalse($durand->isAdministrator());
        self::assertNull($durand->getAdministratorDesignatedBy());
    }

    public function testOnlyATeacherIsDesignated(): void
    {
        $martin = $this->teacher('prof.martin', 'Jean-Pierre Martin');
        $student = new User('entryUUID:s', 'ines.morel', 'Inès Morel', AccountKind::Student, EntryBasis::StudentGroup, ['student', 'cg-1'], new \DateTimeImmutable(self::AT));
        $this->entityManager->persist($student);

        self::assertSame('Seul un enseignant peut être administrateur.', $this->administrators->designate($student, $martin, new \DateTimeImmutable(self::AT)));
        self::assertFalse($student->isAdministrator());
    }

    private function teacher(string $login, string $name): User
    {
        $user = new User('entryUUID:'.$login, $login, $name, AccountKind::Teacher, EntryBasis::TeacherGroup, ['teacher', 'cg-1'], new \DateTimeImmutable(self::AT));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
