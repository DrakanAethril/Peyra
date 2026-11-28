<?php

declare(strict_types=1);

namespace App\Service\Dossier;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\RestorePoint;
use App\Entity\Identity\User;

/**
 * One line of Classe › Dossiers (design-ux.md § 3.3): the dossier, who holds it (its student, or
 * the members of a group), its last restore point whatever it is, its « État de départ » when one
 * is ready, and whether it is going back to a point now.
 */
final readonly class ClassDossierRow
{
    /** @param list<User> $holders */
    public function __construct(
        public Dossier $dossier,
        public array $holders,
        public ?RestorePoint $lastPoint,
        public ?RestorePoint $start,
        public bool $restoring,
    ) {
    }

    public function isGroup(): bool
    {
        return \count($this->holders) > 1 || null === $this->dossier->getOwner();
    }

    /** « Morel Inès », or the members of a group, by name. */
    public function holderLabel(): string
    {
        return [] === $this->holders ? '—' : implode(', ', array_map(static fn (User $user): string => $user->getSortName(), $this->holders));
    }
}
