<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\Accounting\Entry;
use App\Entity\Accounting\EntryLine;
use App\Enum\Accounting\EntryState;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;

/**
 * The accounting invariants held at flush, whoever wrote the rows (README of the plan, § 8;
 * conception § 5) - the Ledger checks first, this guard is what makes a mistake elsewhere fail:
 *
 * - a validated entry never changes and is never deleted, nor does any of its lines, apart from
 *   the lettering (EntryLine::MUTABLE_WHEN_VALIDATED);
 * - only a validated entry's line is lettered: a draft may still change, its letter would lie;
 * - every entry written is balanced: two lines at least, each with a debit or a credit - one of
 *   them, positive - and as much debit as credit.
 *
 * The whole flush fails: nothing of it reaches the database.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class LedgerGuard
{
    public function onFlush(OnFlushEventArgs $event): void
    {
        $unitOfWork = $event->getObjectManager()->getUnitOfWork();
        /** @var array<int, Entry> $touched */
        $touched = [];

        foreach ([...$unitOfWork->getScheduledEntityInsertions(), ...$unitOfWork->getScheduledEntityUpdates()] as $entity) {
            if ($entity instanceof EntryLine && $entity->isLettered() && !$entity->getEntry()->isValidated()) {
                throw new LedgerRefused(\sprintf('A line of entry %s carries the letter %s: only a validated entry is lettered.', $entity->getEntry()->getId() ?? 'new', $entity->getLetter()));
            }
        }

        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Entry) {
                $touched[spl_object_id($entity)] = $entity;
            } elseif ($entity instanceof EntryLine) {
                self::refuseIfValidated($unitOfWork, $entity->getEntry(), 'a line added');
                $touched[spl_object_id($entity->getEntry())] = $entity->getEntry();
            }
        }

        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof Entry) {
                self::refuseIfValidated($unitOfWork, $entity, 'changed');
                $touched[spl_object_id($entity)] = $entity;
            } elseif ($entity instanceof EntryLine) {
                $fields = array_diff(array_keys($unitOfWork->getEntityChangeSet($entity)), EntryLine::MUTABLE_WHEN_VALIDATED);
                if ([] !== $fields) {
                    self::refuseIfValidated($unitOfWork, $entity->getEntry(), 'a line changed ('.implode(', ', $fields).')');
                    $touched[spl_object_id($entity->getEntry())] = $entity->getEntry();
                }
            }
        }

        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof Entry) {
                self::refuseIfValidated($unitOfWork, $entity, 'deleted');
                unset($touched[spl_object_id($entity)]);
            } elseif ($entity instanceof EntryLine && !$unitOfWork->isScheduledForDelete($entity->getEntry())) {
                self::refuseIfValidated($unitOfWork, $entity->getEntry(), 'a line deleted');
                $touched[spl_object_id($entity->getEntry())] = $entity->getEntry();
            }
        }

        foreach ($touched as $entry) {
            if ($unitOfWork->isScheduledForDelete($entry)) {
                continue;
            }
            $fault = self::balanceFault($entry);
            if (null !== $fault) {
                throw new LedgerRefused(\sprintf('Entry %s of %s is refused: %s.', $entry->getId() ?? 'new', $entry->getJournal()->getCode(), $fault));
            }
        }
    }

    /** Why the entry is not balanced, or null. */
    public static function balanceFault(Entry $entry): ?string
    {
        $lines = $entry->getLines();
        if (\count($lines) < 2) {
            return 'fewer than two lines';
        }
        foreach ($lines as $line) {
            $debit = $line->getDebit();
            $credit = $line->getCredit();
            if ($debit->isNegative() || $credit->isNegative() || $debit->isZero() === $credit->isZero()) {
                return 'a line needs a positive debit or a positive credit, not both';
            }
        }
        if (!$entry->totalDebit()->equals($entry->totalCredit())) {
            return 'debit and credit differ';
        }

        return null;
    }

    /** A row of an entry that was validated before this flush may not change. */
    private static function refuseIfValidated(UnitOfWork $unitOfWork, Entry $entry, string $what): void
    {
        if ($unitOfWork->isScheduledForInsert($entry)) {
            return;
        }
        // Its state before this flush: the old value when this flush changes it (a validation),
        // else the one it has.
        $change = $unitOfWork->getEntityChangeSet($entry)['state'] ?? null;
        $state = \is_array($change) ? ($change[0] ?? null) : $entry->getState();
        if (EntryState::Validated === $state) {
            throw new LedgerRefused(\sprintf('Entry %s is validated: it is never %s - it is reversed.', $entry->getNumberLabel(), 'changed' === $what || 'deleted' === $what ? $what : 'changed ('.$what.')'));
        }
    }
}
