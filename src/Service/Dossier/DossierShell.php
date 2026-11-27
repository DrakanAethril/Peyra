<?php

declare(strict_types=1);

namespace App\Service\Dossier;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Dossier\DossierKind;
use App\Repository\Dossier\DossierDateChangeRepository;
use App\Repository\Dossier\DossierRepository;
use App\Repository\Dossier\ExerciseRepository;
use App\Repository\Dossier\PeriodRepository;
use App\Security\Voter\DossierVoter;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * What the PGI shell draws around every screen of a dossier (design-ux.md § 2.1): the switcher, the
 * date chip and its panel (exercise, period, the last three moves), and - for a teacher - the
 * ribbon that says whose dossier this is. Read inside the dossier's context.
 *
 * @phpstan-import-type ListingGroup from DossierListing
 */
final readonly class DossierShell
{
    public function __construct(
        private Security $security,
        private DossierHolders $holders,
        private DossierRepository $dossiers,
        private DossierListing $listing,
        private ExerciseRepository $exercises,
        private PeriodRepository $periods,
        private DossierDateChangeRepository $dateChanges,
    ) {
    }

    public function view(Dossier $dossier, bool $dateMoved): DossierShellView
    {
        $me = $this->security->getUser();
        if (!$me instanceof User) {
            throw new \LogicException('A dossier is opened by a signed-in person.');
        }
        $date = $dossier->getDossierDate();
        $holders = $this->holders->of($dossier);

        return new DossierShellView(
            $dossier,
            $this->exercises->findAt($dossier, $date),
            $this->periods->findAt($dossier, $date),
            $this->dateChanges->findLatest($dossier, 3),
            $this->switcher($dossier, $me, $holders),
            $this->security->isGranted(DossierVoter::EDIT, $dossier),
            $this->security->isGranted(DossierVoter::SET_DATE, $dossier),
            $me->isTeacher(),
            $holders,
            $dateMoved,
        );
    }

    /**
     * The student's own dossiers; for a teacher in a student's dossier, that student's dossiers
     * they may open; a group's dossier or a template alone otherwise.
     *
     * @param list<User> $holders
     *
     * @return list<ListingGroup>
     */
    private function switcher(Dossier $dossier, User $me, array $holders): array
    {
        if ($me->isStudent()) {
            $mine = $this->dossiers->findForStudent($me);
        } elseif (DossierKind::Student === $dossier->getKind() && 1 === \count($holders)) {
            $mine = array_values(array_filter(
                $this->dossiers->findForStudent($holders[0]),
                fn (Dossier $other): bool => $this->security->isGranted(DossierVoter::VIEW, $other),
            ));
        } else {
            $mine = [$dossier];
        }

        return $this->listing->byYear($mine);
    }
}
