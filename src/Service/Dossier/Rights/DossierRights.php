<?php

declare(strict_types=1);

namespace App\Service\Dossier\Rights;

use App\Entity\Accounting\Journal;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Dossier\AccessLevel;
use App\Enum\Dossier\DossierArea;
use App\Enum\Dossier\DossierKind;
use App\Enum\Dossier\DossierProfile;
use App\Repository\Dossier\DossierMemberRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The profile layer of a dossier's rights (lot 1, step 1.7): who may work in a dossier is
 * DossierVoter's question; how far, area by area, is this one's - the member's profile in a group's
 * dossier read through ProfileMatrix, every right for anybody else. Asked by the doors themselves
 * (Ledger, Lettering) and by DossierRightVoter for the screens. Global reads only; the profiles
 * are kept for the request (the worker resets them).
 */
final class DossierRights implements ResetInterface
{
    /** @var array<string, DossierProfile|null> by dossier and person */
    private array $profiles = [];

    public function __construct(private readonly DossierMemberRepository $members)
    {
    }

    /** The person's profile in a group's dossier; null for anybody else and for a member without one. */
    public function profileOf(Dossier $dossier, User $user): ?DossierProfile
    {
        if (DossierKind::Group !== $dossier->getKind() || !$user->isStudent()) {
            return null;
        }
        $key = $dossier->getId().'-'.$user->getId();
        if (!\array_key_exists($key, $this->profiles)) {
            $this->profiles[$key] = $this->members->findOneFor($dossier, $user)?->getProfile();
        }

        return $this->profiles[$key];
    }

    public function level(Dossier $dossier, User $user, DossierArea $area): AccessLevel
    {
        return ProfileMatrix::level($this->profileOf($dossier, $user), $area);
    }

    public function allows(Dossier $dossier, User $user, DossierArea $area, AccessLevel $wanted): bool
    {
        return $this->level($dossier, $user, $area)->allows($wanted);
    }

    /** Why the person may not do this in the journal, null when they may (a command's actor always may). */
    public function journalRefusal(Journal $journal, User|string $actor, AccessLevel $wanted): ?string
    {
        if (!$actor instanceof User) {
            return null;
        }

        return ProfileMatrix::refusal($this->profileOf($journal->getDossier(), $actor), DossierArea::ofJournal($journal->getType()), $wanted, Gesture::journal($journal->getType(), $wanted));
    }

    /** Why the person may not do this in an area, null when they may. */
    public function refusal(Dossier $dossier, User|string $actor, DossierArea $area, AccessLevel $wanted, string $gesture): ?string
    {
        if (!$actor instanceof User) {
            return null;
        }

        return ProfileMatrix::refusal($this->profileOf($dossier, $actor), $area, $wanted, $gesture);
    }

    public function reset(): void
    {
        $this->profiles = [];
    }
}
