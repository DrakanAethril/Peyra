<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\Shared\AppendOnly;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Refuses, before anything reaches the database, every update and every removal of an
 * append-only row (README of the plan, § 8). The whole flush fails: nothing of it is written.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class AppendOnlyGuard
{
    public function onFlush(OnFlushEventArgs $event): void
    {
        $unitOfWork = $event->getObjectManager()->getUnitOfWork();
        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof AppendOnly) {
                throw new AppendOnlyRefused(\sprintf('%s is append-only: it is never changed, a correction is a new row.', $entity::class));
            }
        }
        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof AppendOnly) {
                throw new AppendOnlyRefused(\sprintf('%s is append-only: it is never deleted.', $entity::class));
            }
        }
    }
}
