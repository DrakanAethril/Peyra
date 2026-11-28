<?php

declare(strict_types=1);

namespace App\Service\Audit;

use App\Entity\Audit\AuditEvent;
use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierMember;
use App\Entity\Identity\User;
use App\Enum\Audit\AuditAction;
use App\Enum\Audit\AuditActorRole;
use App\Enum\Dossier\DossierKind;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * The one writer of the audit trail (README of the plan, § 8): it stamps the real time itself -
 * the trail is the one business place that reads the clock - and persists the line, which the
 * caller flushes with the gesture it records, so a gesture that fails leaves no line. Lines are
 * never changed nor deleted (App\Doctrine\AppendOnlyGuard).
 *
 * A gesture made in a dossier goes through inDossier(), which adds the dossier, its date and what
 * the author was there (step 1.7); record() refuses the subject « dossier », so no gesture of a
 * dossier escapes its trail.
 */
final readonly class AuditTrail
{
    public const string DOSSIER = 'dossier';

    public function __construct(private EntityManagerInterface $entityManager, private ClockInterface $clock)
    {
    }

    /**
     * A gesture in a dossier: who - and whether a teacher or a student, with their profile in a
     * group's dossier -, what, when in real time and at the dossier's date.
     *
     * @param User|string          $actor   the person, or the name a command signs with
     * @param array<string, mixed> $details what the line carries beyond its sentence (JSON)
     */
    public function inDossier(Dossier $dossier, AuditAction $action, User|string $actor, string $summary, array $details = []): AuditEvent
    {
        $event = $this->line($action, $actor, self::DOSSIER, (string) ($dossier->getId() ?? throw new \LogicException('A dossier’s trail starts once it has an id.')), $summary, $details);
        $role = null;
        $profile = null;
        if ($actor instanceof User) {
            $role = $actor->isTeacher() ? AuditActorRole::Teacher : AuditActorRole::Student;
            if (AuditActorRole::Student === $role && DossierKind::Group === $dossier->getKind()) {
                $profile = $this->entityManager->getRepository(DossierMember::class)->findOneBy(['dossier' => $dossier, 'student' => $actor])?->getProfile();
            }
        }
        $event->placeInDossier((int) $dossier->getId(), $dossier->getDossierDate(), $role, $profile);
        $this->entityManager->persist($event);

        return $event;
    }

    /**
     * @param User|string          $actor   the person, or the name a command or the scheduler signs with
     * @param array<string, mixed> $details what the line carries beyond its sentence (JSON)
     */
    public function record(AuditAction $action, User|string $actor, string $subjectType, string $subjectId, string $summary, array $details = []): AuditEvent
    {
        if (self::DOSSIER === $subjectType) {
            throw new \LogicException('A gesture in a dossier is recorded by AuditTrail::inDossier().');
        }
        $event = $this->line($action, $actor, $subjectType, $subjectId, $summary, $details);
        $this->entityManager->persist($event);

        return $event;
    }

    /** @param array<string, mixed> $details */
    private function line(AuditAction $action, User|string $actor, string $subjectType, string $subjectId, string $summary, array $details): AuditEvent
    {
        return new AuditEvent(
            $this->clock->now(),
            $actor instanceof User ? $actor : null,
            $actor instanceof User ? $actor->getDisplayName() : $actor,
            $action,
            $subjectType,
            $subjectId,
            mb_strimwidth($summary, 0, 255, '…'),
            $details,
        );
    }
}
