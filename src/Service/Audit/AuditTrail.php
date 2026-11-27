<?php

declare(strict_types=1);

namespace App\Service\Audit;

use App\Entity\Audit\AuditEvent;
use App\Entity\Identity\User;
use App\Enum\Audit\AuditAction;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * The one writer of the audit trail (README of the plan, § 8): it stamps the real time itself -
 * the trail is the one business place that reads the clock - and persists the line, which the
 * caller flushes with the gesture it records, so a gesture that fails leaves no line. Lines are
 * never changed nor deleted (App\Doctrine\AppendOnlyGuard).
 */
final readonly class AuditTrail
{
    public function __construct(private EntityManagerInterface $entityManager, private ClockInterface $clock)
    {
    }

    /**
     * @param User|string          $actor   the person, or the name a command or the scheduler signs with
     * @param array<string, mixed> $details what the line carries beyond its sentence (JSON)
     */
    public function record(AuditAction $action, User|string $actor, string $subjectType, string $subjectId, string $summary, array $details = []): AuditEvent
    {
        $event = new AuditEvent(
            $this->clock->now(),
            $actor instanceof User ? $actor : null,
            $actor instanceof User ? $actor->getDisplayName() : $actor,
            $action,
            $subjectType,
            $subjectId,
            mb_strimwidth($summary, 0, 255, '…'),
            $details,
        );
        $this->entityManager->persist($event);

        return $event;
    }
}
