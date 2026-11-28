<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\RestorePoint;
use App\Entity\Identity\User;
use App\Enum\Dossier\DossierKind;
use App\Enum\Dossier\RestorePointOrigin;
use App\Enum\Identity\AccountKind;
use App\Security\Voter\RestorePointVoter;
use App\Tests\Support\BuildsGroupDossier;
use App\Tests\Support\DossierFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Who does what with a dossier's restore points (step 1.8b): whoever works in the dossier poses
 * one; going back - and deleting a point - undoes everybody's work, so it asks the right on the
 * dossier's settings: a group's member without a profile and the class's teacher, not the
 * « Comptable fournisseurs ». Nobody outside the dossier, and never on a restore point itself.
 */
final class RestorePointVoterTest extends KernelTestCase
{
    use BuildsGroupDossier;

    public function testEachMemberAsTheirRightsSay(): void
    {
        self::bootKernel();
        [$dossier, $people] = $this->groupDossier();
        $point = $this->point($dossier, $people['durand']);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $stranger = $this->groupAccount($entityManager, AccountKind::Student, 'lucas');
        $entityManager->flush();

        self::assertSame([true, true, true], $this->rights($people['chloe'], $dossier, $point), 'chloe, sans profil');
        self::assertSame([true, true, true], $this->rights($people['durand'], $dossier, $point), 'durand, enseignant de la classe');
        self::assertSame([true, false, false], $this->rights($people['ines'], $dossier, $point), 'ines, comptable fournisseurs');
        self::assertSame([false, false, false], $this->rights($stranger, $dossier, $point), 'lucas, hors du dossier');
        self::assertFalse($this->grants($people['durand'], RestorePointVoter::POSE, $point->getSnapshot()), 'Jamais de point sur un point.');
    }

    public function testTheStartingPointIsDeletedByATeacherOnly(): void
    {
        self::bootKernel();
        [$dossier, $people] = $this->groupDossier();
        $start = $this->point($dossier, $people['durand'], RestorePointOrigin::Start);

        self::assertSame([true, true, false], $this->rights($people['chloe'], $dossier, $start), 'chloe revient à l’état de départ, sans le supprimer');
        self::assertSame([true, true, true], $this->rights($people['durand'], $dossier, $start), 'durand, enseignant de la classe');
    }

    private function point(Dossier $dossier, User $author, RestorePointOrigin $origin = RestorePointOrigin::Manual): RestorePoint
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $snapshot = new Dossier(DossierKind::Snapshot, DossierFixtures::company(), $dossier->getDossierDate(), null, null, new \DateTimeImmutable('2026-11-28'), $author);
        $entityManager->persist($snapshot);
        $point = new RestorePoint($dossier, $snapshot, 'Avant validation', $origin, $author, new \DateTimeImmutable('2026-11-28'));
        $entityManager->persist($point);
        $entityManager->flush();

        return $point;
    }

    /** @return list<bool> POSE, RETURN, DELETE */
    private function rights(User $user, Dossier $dossier, RestorePoint $point): array
    {
        return [
            $this->grants($user, RestorePointVoter::POSE, $dossier),
            $this->grants($user, RestorePointVoter::RETURN, $point),
            $this->grants($user, RestorePointVoter::DELETE, $point),
        ];
    }

    private function grants(User $user, string $attribute, Dossier|RestorePoint $subject): bool
    {
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        return VoterInterface::ACCESS_GRANTED === self::getContainer()->get(RestorePointVoter::class)->vote($token, $subject, [$attribute]);
    }
}
