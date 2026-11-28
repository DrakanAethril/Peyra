<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierMember;
use App\Entity\Identity\Enrolment;
use App\Entity\Identity\SchoolClass;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\TeacherClass;
use App\Entity\Identity\User;
use App\Enum\Dossier\DossierKind;
use App\Enum\Dossier\DossierProfile;
use App\Enum\Identity\AccountKind;
use App\Enum\Identity\ClassLevel;
use App\Enum\Identity\EnrolmentOrigin;
use App\Enum\Identity\EntryBasis;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A group's dossier ready for entries (BuildsLedger's journals and third parties), for a kernel
 * test: a current year and its BTS CG 1, a teacher of it (durand), three students enrolled and
 * members - ines « Comptable fournisseurs », theo « Comptable clients », chloe without a profile.
 * In a file of its own: ParaTest hands out files.
 */
trait BuildsGroupDossier
{
    use BuildsLedger;

    /** @return array{Dossier, array{ines: User, theo: User, chloe: User, durand: User}} */
    private function groupDossier(): array
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $at = new \DateTimeImmutable('2026-09-01');
        $year = new SchoolYear(new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2027-08-31'), $at);
        $year->markCurrent();
        $class = new SchoolClass($year, 'BTS CG 1', ClassLevel::First, $at);
        $entityManager->persist($year);
        $entityManager->persist($class);
        $people = [];
        foreach (['ines' => AccountKind::Student, 'theo' => AccountKind::Student, 'chloe' => AccountKind::Student, 'durand' => AccountKind::Teacher] as $login => $kind) {
            $people[$login] = $this->groupAccount($entityManager, $kind, $login);
        }
        $entityManager->persist(new TeacherClass($people['durand'], $class, $at, null));
        $entityManager->flush();

        $dossier = $this->savedDossier(DossierKind::Group, null, $year);
        foreach (['ines' => DossierProfile::SupplierAccountant, 'theo' => DossierProfile::CustomerAccountant, 'chloe' => null] as $login => $profile) {
            $entityManager->persist(new Enrolment($people[$login], $class, EnrolmentOrigin::Manual, $at, null));
            $member = new DossierMember($dossier, $people[$login], $at);
            $member->assignProfile($profile);
            $entityManager->persist($member);
        }
        $entityManager->flush();
        $this->installPlan($dossier);
        $this->prepareLedger($dossier);

        return [$dossier, $people];
    }

    private function groupAccount(EntityManagerInterface $entityManager, AccountKind $kind, string $login): User
    {
        $student = AccountKind::Student === $kind;
        $user = new User('uid:'.$login, $login, ucfirst($login), $kind, $student ? EntryBasis::StudentGroup : EntryBasis::TeacherGroup, [$student ? 'student' : 'teacher', 'cg-1'], new \DateTimeImmutable('2026-01-01'));
        $entityManager->persist($user);

        return $user;
    }
}
