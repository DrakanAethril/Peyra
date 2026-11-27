<?php

declare(strict_types=1);

namespace App\Service\Identity;

use App\Entity\Identity\Enrolment;
use App\Entity\Identity\User;
use App\Enum\Identity\EnrolmentOrigin;
use App\Repository\Identity\DirectoryGroupMappingRepository;
use App\Repository\Identity\SchoolYearRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Places a student whose account is being created (AccountProvisioner), once: the class their
 * directory groups designate in the current year's mapping (PlacementRule), else none - they wait
 * in « À placer ». The outcome is kept on the account. The caller flushes.
 */
final readonly class InitialPlacement
{
    public function __construct(
        private SchoolYearRepository $years,
        private DirectoryGroupMappingRepository $mappings,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function place(User $student, \DateTimeImmutable $at): void
    {
        if (!$student->isStudent() || null !== $student->getInitialPlacement()) {
            return;
        }

        $year = $this->years->findCurrent();
        $classes = [];
        $classByGroup = null;
        if (null !== $year) {
            $classByGroup = [];
            foreach ($this->mappings->findByYear($year) as $group => $mapping) {
                $class = $mapping->getSchoolClass();
                $classes[(int) $class->getId()] = $class;
                $classByGroup[$group] = (int) $class->getId();
            }
        }

        $decision = PlacementRule::decide($student->getGroupsAtCreation(), $classByGroup);
        $student->recordInitialPlacement($decision->outcome);
        if (null !== $decision->classId) {
            $this->entityManager->persist(new Enrolment($student, $classes[$decision->classId], EnrolmentOrigin::Directory, $at, null));
        }
    }
}
