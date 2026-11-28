<?php

declare(strict_types=1);

namespace App\Service\Shared\Assistance;

use App\Entity\Identity\User;
use App\Enum\Accounting\AssistanceLevel;
use App\Enum\Identity\AdministrationAction;
use App\Service\Identity\AdministrationJournal;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one door to a student's own assistance level (Fiche étudiant › Aménagements): set or given
 * back to the mission's, each change in the administration journal. It takes effect at the
 * student's next screen.
 */
final readonly class StudentAssistance
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AdministrationJournal $journal,
    ) {
    }

    /** False when the level was already that one (nothing is written). */
    public function set(User $student, ?AssistanceLevel $level, User $by, \DateTimeImmutable $at): bool
    {
        if ($student->getAssistanceLevel() === $level) {
            return false;
        }
        $before = $student->getAssistanceLevel();
        $student->setAssistanceLevel($level);
        $this->journal->recordStudent($at, $by, AdministrationAction::StudentAssistanceChanged, $student, \sprintf('%s → %s', self::label($before), self::label($level)));
        $this->entityManager->flush();

        return true;
    }

    /** « Contrôles bloquants », or « Celui de la mission » for none. */
    public static function label(?AssistanceLevel $level): string
    {
        return $level?->label() ?? 'Celui de la mission';
    }
}
