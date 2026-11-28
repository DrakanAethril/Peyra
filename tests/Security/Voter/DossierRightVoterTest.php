<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Accounting\Journal;
use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierMember;
use App\Entity\Identity\User;
use App\Enum\Dossier\DossierProfile;
use App\Enum\Identity\AccountKind;
use App\Repository\Accounting\JournalRepository;
use App\Security\Voter\DossierRightVoter;
use App\Service\Dossier\Rights\DossierRights;
use App\Tests\Support\BuildsGroupDossier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * A dossier's rights area by area (lot 1, step 1.7): the profile of a group's member narrows what
 * DossierVoter lets them do - a closed journal is not even read on its screen, a read one is not
 * entered, an entered one is not validated, the settings are the chief's; a member without a
 * profile and the class's teacher have every right; somebody outside the dossier has none.
 */
final class DossierRightVoterTest extends KernelTestCase
{
    use BuildsGroupDossier;

    private const array JOURNAL = [DossierRightVoter::JOURNAL_READ, DossierRightVoter::JOURNAL_ENTER, DossierRightVoter::JOURNAL_VALIDATE];

    public function testEachMemberAsTheirProfileSays(): void
    {
        self::bootKernel();
        [$dossier, $people] = $this->groupDossier();

        // ines, « Comptable fournisseurs ».
        self::assertSame([true, true, true], $this->journal($people['ines'], $dossier, 'ACH'));
        self::assertSame([false, false, false], $this->journal($people['ines'], $dossier, 'VTE'), 'The sales are closed to her.');
        self::assertSame([true, true, false], $this->journal($people['ines'], $dossier, 'BQ'), 'She enters the payments, the chief validates them.');
        self::assertSame([true, false, false], $this->journal($people['ines'], $dossier, 'OD'));
        self::assertSame([true, false, false], $this->journal($people['ines'], $dossier, 'AN'));
        self::assertFalse($this->settings($people['ines'], $dossier));
        self::assertSame([false, false, false], $this->payroll($people['ines'], $dossier), 'The payroll is closed to the accountants (step 4.1).');

        // theo, « Comptable clients ».
        self::assertSame([false, false, false], $this->journal($people['theo'], $dossier, 'ACH'));
        self::assertSame([true, true, true], $this->journal($people['theo'], $dossier, 'VTE'));
        self::assertSame([false, false, false], $this->payroll($people['theo'], $dossier));

        // chloe has no profile, durand teaches the class: every right.
        foreach (['chloe', 'durand'] as $login) {
            foreach (['ACH', 'VTE', 'BQ', 'OD', 'AN'] as $code) {
                self::assertSame([true, true, true], $this->journal($people[$login], $dossier, $code), $login.' '.$code);
            }
            self::assertTrue($this->settings($people[$login], $dossier), $login);
            self::assertSame([true, true, true], $this->payroll($people[$login], $dossier), $login);
        }
    }

    /** Ventes (step 2.3): the customer accountant's, closed to the supplier accountant; every right without a profile. */
    public function testTheSalesFollowTheSalesArea(): void
    {
        self::bootKernel();
        [$dossier, $people] = $this->groupDossier();

        self::assertSame([false, false, false], $this->sales($people['ines'], $dossier));
        self::assertSame([true, true, true], $this->sales($people['theo'], $dossier));
        self::assertSame([true, true, true], $this->sales($people['chloe'], $dossier));
        self::assertSame([true, true, true], $this->sales($people['durand'], $dossier));
    }

    /** Achats (step 2.4): the supplier accountant's, closed to the customer accountant; every right without a profile. */
    public function testThePurchasesFollowThePurchasesArea(): void
    {
        self::bootKernel();
        [$dossier, $people] = $this->groupDossier();

        self::assertSame([true, true, true], $this->purchases($people['ines'], $dossier));
        self::assertSame([false, false, false], $this->purchases($people['theo'], $dossier));
        self::assertSame([true, true, true], $this->purchases($people['chloe'], $dossier));
        self::assertSame([true, true, true], $this->purchases($people['durand'], $dossier));
    }

    /** Immobilisations (step 3.4): the general accounting's area - read by the two accountants, kept by the chief accountant; every right without a profile. */
    public function testTheFixedAssetsFollowTheGeneralArea(): void
    {
        self::bootKernel();
        [$dossier, $people] = $this->groupDossier();
        $voter = self::getContainer()->get(DossierRightVoter::class);
        $rights = static fn (User $user): array => array_map(static fn (string $attribute): bool => VoterInterface::ACCESS_GRANTED === $voter->vote(new UsernamePasswordToken($user, 'main', $user->getRoles()), $dossier, [$attribute]), [DossierRightVoter::ASSETS_READ, DossierRightVoter::ASSETS_ENTER]);

        self::assertSame([true, false], $rights($people['ines']));
        self::assertSame([true, false], $rights($people['theo']));
        self::assertSame([true, true], $rights($people['chloe']));
        self::assertSame([true, true], $rights($people['durand']));
    }

    public function testNobodyOutsideTheDossier(): void
    {
        self::bootKernel();
        [$dossier] = $this->groupDossier();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $stranger = $this->groupAccount($entityManager, AccountKind::Student, 'lucas');
        $entityManager->flush();

        self::assertSame([false, false, false], $this->journal($stranger, $dossier, 'ACH'));
        self::assertFalse($this->settings($stranger, $dossier));
        self::assertSame([false, false, false], $this->payroll($stranger, $dossier));
    }

    public function testThePayrollManagerKeepsThePayroll(): void
    {
        self::bootKernel();
        [$dossier, $people] = $this->groupDossier();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $member = $entityManager->getRepository(DossierMember::class)->findOneBy(['dossier' => $dossier, 'student' => $people['theo']]);
        self::assertInstanceOf(DossierMember::class, $member);
        $member->assignProfile(DossierProfile::PayrollManager);
        $entityManager->flush();
        self::getContainer()->get(DossierRights::class)->reset();

        self::assertSame([true, true, true], $this->payroll($people['theo'], $dossier));
        self::assertFalse($this->settings($people['theo'], $dossier), 'The accounting settings stay the chief’s.');
    }

    /** @return list<bool> SALES_READ, SALES_ENTER, SALES_VALIDATE on the dossier */
    private function sales(User $user, Dossier $dossier): array
    {
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $voter = self::getContainer()->get(DossierRightVoter::class);

        return array_map(static fn (string $attribute): bool => VoterInterface::ACCESS_GRANTED === $voter->vote($token, $dossier, [$attribute]), [DossierRightVoter::SALES_READ, DossierRightVoter::SALES_ENTER, DossierRightVoter::SALES_VALIDATE]);
    }

    /** @return list<bool> PURCHASES_READ, PURCHASES_ENTER, PURCHASES_VALIDATE on the dossier */
    private function purchases(User $user, Dossier $dossier): array
    {
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $voter = self::getContainer()->get(DossierRightVoter::class);

        return array_map(static fn (string $attribute): bool => VoterInterface::ACCESS_GRANTED === $voter->vote($token, $dossier, [$attribute]), [DossierRightVoter::PURCHASES_READ, DossierRightVoter::PURCHASES_ENTER, DossierRightVoter::PURCHASES_VALIDATE]);
    }

    /** @return list<bool> PAYROLL_READ, PAYROLL_SETTINGS, PAYROLL_ENTER on the dossier */
    private function payroll(User $user, Dossier $dossier): array
    {
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $voter = self::getContainer()->get(DossierRightVoter::class);

        return array_map(static fn (string $attribute): bool => VoterInterface::ACCESS_GRANTED === $voter->vote($token, $dossier, [$attribute]), [DossierRightVoter::PAYROLL_READ, DossierRightVoter::PAYROLL_SETTINGS, DossierRightVoter::PAYROLL_ENTER]);
    }

    /** @return list<bool> READ, ENTER, VALIDATE on the journal */
    private function journal(User $user, Dossier $dossier, string $code): array
    {
        $journal = self::getContainer()->get(JournalRepository::class)->findOneByCode($dossier, $code);
        self::assertInstanceOf(Journal::class, $journal);
        $voter = self::getContainer()->get(DossierRightVoter::class);
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        return array_map(static fn (string $attribute): bool => VoterInterface::ACCESS_GRANTED === $voter->vote($token, $journal, [$attribute]), self::JOURNAL);
    }

    private function settings(User $user, Dossier $dossier): bool
    {
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        return VoterInterface::ACCESS_GRANTED === self::getContainer()->get(DossierRightVoter::class)->vote($token, $dossier, [DossierRightVoter::SETTINGS]);
    }
}
