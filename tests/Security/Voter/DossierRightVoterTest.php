<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Accounting\Journal;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Identity\AccountKind;
use App\Repository\Accounting\JournalRepository;
use App\Security\Voter\DossierRightVoter;
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

        // theo, « Comptable clients ».
        self::assertSame([false, false, false], $this->journal($people['theo'], $dossier, 'ACH'));
        self::assertSame([true, true, true], $this->journal($people['theo'], $dossier, 'VTE'));

        // chloe has no profile, durand teaches the class: every right.
        foreach (['chloe', 'durand'] as $login) {
            foreach (['ACH', 'VTE', 'BQ', 'OD', 'AN'] as $code) {
                self::assertSame([true, true, true], $this->journal($people[$login], $dossier, $code), $login.' '.$code);
            }
            self::assertTrue($this->settings($people[$login], $dossier), $login);
        }
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
