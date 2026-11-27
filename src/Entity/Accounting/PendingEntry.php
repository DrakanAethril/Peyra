<?php

declare(strict_types=1);

namespace App\Entity\Accounting;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierOwned;
use App\Entity\Dossier\DossierOwnedTrait;
use App\Entity\Dossier\Period;
use App\Entity\Identity\User;
use App\Repository\Accounting\PendingEntryRepository;
use App\Service\Accounting\Entry\GridGroup;
use App\Service\Shared\JsonRequestPayload;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * What a person has typed in the entry grid of a journal and a month and not yet saved to the
 * brouillard (« saisie en attente », lot 1, note of 2026-11-27): the entries still unbalanced or
 * incomplete, and the drafts being changed. It is not an Entry - an entry is balanced or does not
 * exist - and stays outside the ledger: no balance reads it. Kept on every line left, taken back
 * as is when the screen opens again, turned into drafts by F10 as soon as each entry balances. One
 * per journal, month and author; the live follow-up (step 2.10) reads it for « écriture
 * déséquilibrée laissée ».
 *
 * `revision` grows at each save: a save from a tab that read an older one is refused (two tabs on
 * one grid, design-ux.md § 6.5).
 */
#[ORM\Entity(repositoryClass: PendingEntryRepository::class)]
#[ORM\Table(name: 'pending_entry')]
#[ORM\UniqueConstraint(name: 'uniq_pending_entry', columns: ['journal_id', 'period_id', 'author_id'])]
class PendingEntry implements DossierOwned
{
    use DossierOwnedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Journal $journal;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Period $period;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $author;

    #[ORM\Column]
    private int $revision = 0;

    /** The groups as JSON (GridGroup::toArray()), a text column read by GridGroup::fromPayload(). */
    #[ORM\Column(name: 'typed_groups', type: Types::TEXT)]
    private string $groups = '[]';

    /** The real time of the last save: for the live follow-up, never for a business calculation. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $savedAt;

    public function __construct(Dossier $dossier, Journal $journal, Period $period, User $author, \DateTimeImmutable $savedAt)
    {
        $this->dossier = $dossier;
        $this->journal = $journal;
        $this->period = $period;
        $this->author = $author;
        $this->savedAt = $savedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJournal(): Journal
    {
        return $this->journal;
    }

    public function getPeriod(): Period
    {
        return $this->period;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function getRevision(): int
    {
        return $this->revision;
    }

    public function getSavedAt(): \DateTimeImmutable
    {
        return $this->savedAt;
    }

    /** @return list<GridGroup> */
    public function getGroups(): array
    {
        return array_map(GridGroup::fromPayload(...), JsonRequestPayload::listFromJson($this->groups));
    }

    /** @param list<GridGroup> $groups */
    public function replaceGroups(array $groups, \DateTimeImmutable $savedAt): void
    {
        $this->groups = (string) json_encode(array_map(static fn (GridGroup $group): array => $group->toArray(), $groups), \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        $this->savedAt = $savedAt;
        ++$this->revision;
    }
}
