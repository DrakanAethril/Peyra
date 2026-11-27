<?php

declare(strict_types=1);

namespace App\Service\Accounting\Entry;

use App\Entity\Accounting\Journal;
use App\Entity\Accounting\PendingEntry;
use App\Entity\Dossier\Period;
use App\Entity\Identity\User;
use App\Repository\Accounting\PendingEntryRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one door of the « saisies en attente » (PendingEntry): what a person typed in a journal's grid
 * for a month and has not yet saved to the brouillard. Kept whole at each save, blank groups
 * dropped; never deleted - an empty one says nothing is pending, and its revision keeps growing so
 * that a stale tab is always refused. The caller flushes. Real time comes from the caller.
 */
final readonly class PendingEntries
{
    public const int MAX_GROUPS = 200;
    public const int MAX_LINES = 99;

    public function __construct(private PendingEntryRepository $pending, private EntityManagerInterface $entityManager)
    {
    }

    public function of(Journal $journal, Period $period, User $author): ?PendingEntry
    {
        return $this->pending->findOne($journal, $period, $author);
    }

    /** @return list<GridGroup> */
    public function groups(Journal $journal, Period $period, User $author): array
    {
        return $this->of($journal, $period, $author)?->getGroups() ?? [];
    }

    public function revision(Journal $journal, Period $period, User $author): int
    {
        return $this->of($journal, $period, $author)?->getRevision() ?? 0;
    }

    /**
     * The groups kept as the person's pending work in this grid, if `revision` is the one they read.
     *
     * @param list<GridGroup> $groups
     *
     * @throws PendingStale
     */
    public function keep(Journal $journal, Period $period, User $author, array $groups, ?int $revision, \DateTimeImmutable $now): PendingEntry
    {
        $row = $this->of($journal, $period, $author);
        $current = $row?->getRevision() ?? 0;
        if (null !== $revision && $revision !== $current) {
            throw new PendingStale($current);
        }
        if (null === $row) {
            $row = new PendingEntry($journal->getDossier(), $journal, $period, $author, $now);
            $this->entityManager->persist($row);
        }
        $kept = array_values(array_filter($groups, static fn (GridGroup $group): bool => !$group->isBlank()));
        $row->replaceGroups(\array_slice($kept, 0, self::MAX_GROUPS), $now);

        return $row;
    }
}
