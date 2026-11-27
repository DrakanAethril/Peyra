<?php

declare(strict_types=1);

namespace App\Service\Dossier;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierDateChange;
use App\Entity\Dossier\Exercise;
use App\Entity\Dossier\Period;
use App\Entity\Identity\User;

/**
 * DossierShell's reading for the templates of a dossier's screens.
 *
 * @phpstan-import-type ListingGroup from DossierListing
 */
final readonly class DossierShellView
{
    /**
     * @param list<DossierDateChange> $dateChanges the latest first
     * @param list<ListingGroup>      $switcher
     * @param list<User>              $holders     the student, or the group's members
     */
    public function __construct(
        public Dossier $dossier,
        public ?Exercise $exercise,
        public ?Period $period,
        public array $dateChanges,
        public array $switcher,
        public bool $canEdit,
        public bool $canSetDate,
        public bool $teacher,
        public array $holders,
        public bool $dateMoved,
    ) {
    }

    /** How many dossiers the switcher lists: with one, it is a text without a menu. */
    public function switcherCount(): int
    {
        return array_sum(array_map(static fn (array $group): int => \count($group['rows']), $this->switcher));
    }

    /** « Manon Chevalier », « Lucas Bernard, Manon Chevalier »: whose dossier a teacher is in. */
    public function holderNames(): string
    {
        return implode(', ', array_map(static fn (User $holder): string => $holder->getDisplayName(), $this->holders));
    }
}
