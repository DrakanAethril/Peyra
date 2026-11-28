<?php

declare(strict_types=1);

namespace App\Service\Dossier;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\RestorePoint;
use App\Entity\Identity\SchoolClass;
use App\Entity\Identity\User;
use App\Enum\Dossier\DossierKind;
use App\Enum\Dossier\RestorePointOrigin;
use App\Repository\Dossier\DossierMemberRepository;
use App\Repository\Dossier\DossierRepository;
use App\Repository\Dossier\DossierRestorationRepository;
use App\Repository\Dossier\RestorePointRepository;
use App\Repository\Identity\EnrolmentRepository;

/**
 * Classe › Dossiers (design-ux.md §§ 2.6 and 3.3): the dossiers of the class's school year held by
 * the students enrolled in it - their own, and the groups' one of them is in -, by holder, with a
 * fixed number of queries. Who may see each line is the caller's to ask (DossierVoter::VIEW).
 */
final readonly class ClassDossiers
{
    public function __construct(
        private EnrolmentRepository $enrolments,
        private DossierRepository $dossiers,
        private DossierMemberRepository $members,
        private RestorePointRepository $points,
        private DossierRestorationRepository $restorations,
    ) {
    }

    /** @return list<ClassDossierRow> */
    public function rows(SchoolClass $class): array
    {
        $studentIds = array_map(static fn ($enrolment): int => (int) $enrolment->getStudent()->getId(), $this->enrolments->findByClass($class));

        return $this->rowsOf($this->dossiers->findOfStudentsInYear($studentIds, $class->getSchoolYear()));
    }

    /**
     * The same lines for given dossiers (a distribution's), by holder.
     *
     * @param list<Dossier> $dossiers
     *
     * @return list<ClassDossierRow>
     */
    public function rowsOf(array $dossiers): array
    {
        $groups = array_values(array_filter($dossiers, static fn (Dossier $dossier): bool => DossierKind::Group === $dossier->getKind()));
        $members = $this->members->findStudentsOf($groups);
        $points = $this->points->findByDossiers($dossiers);
        $restoring = $this->restorations->findRunningDossierIds($dossiers);
        $rows = [];
        foreach ($dossiers as $dossier) {
            $id = (int) $dossier->getId();
            $owner = $dossier->getOwner();
            $holders = DossierKind::Group === $dossier->getKind() ? ($members[$id] ?? []) : (null === $owner ? [] : [$owner]);
            $mine = $points[$id] ?? [];
            $start = array_find($mine, static fn (RestorePoint $point): bool => RestorePointOrigin::Start === $point->getOrigin() && $point->isReady());
            $rows[] = new ClassDossierRow($dossier, $holders, $mine[0] ?? null, $start, \in_array($id, $restoring, true));
        }
        usort($rows, static fn (ClassDossierRow $a, ClassDossierRow $b): int => [self::firstName($a->holders), (int) $a->dossier->getId()] <=> [self::firstName($b->holders), (int) $b->dossier->getId()]);

        return $rows;
    }

    /** @param list<User> $holders */
    private static function firstName(array $holders): string
    {
        return [] === $holders ? '' : $holders[0]->getSortName();
    }
}
