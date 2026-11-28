<?php

declare(strict_types=1);

namespace App\Service\Shared\Assistance;

use App\Enum\Accounting\AssistanceLevel;

/**
 * What an assistance level shows, proposes and stops (design-ux.md §§ 3.2 and 3.4, the « Assistance »
 * column): the one place these decisions are written. Every screen and service that behaves
 * differently by level asks this object - never compares the level itself - so that a level means
 * the same thing everywhere. It changes what is proposed and what is stopped, never the layout.
 *
 * A pure value: `Assistance::at($level)`, the level being the one AssistanceLevels resolves for a
 * dossier and the person working in it.
 */
final readonly class Assistance
{
    private function __construct(public AssistanceLevel $level)
    {
    }

    public static function at(AssistanceLevel $level): self
    {
        return new self($level);
    }

    /** The controls of an entry run (G, B, A); at level 0 only what is refused at every level remains (balance, closed period). */
    public function runsControls(): bool
    {
        return AssistanceLevel::None !== $this->level;
    }

    /**
     * A control stops (G and B) rather than warns (A): the entry does not reach the brouillard, a
     * draft it stops cannot be ticked for validation, a lettering selection must balance.
     */
    public function blocks(): bool
    {
        return AssistanceLevel::Guided === $this->level || AssistanceLevel::Blocking === $this->level;
    }

    /** Lettering may leave a selection unbalanced, as a partial (A and 0). */
    public function allowsPartialLettering(): bool
    {
        return !$this->blocks();
    }

    /** Lettering opens on the automatic proposal by amount (G), to be confirmed. */
    public function proposesLettering(): bool
    {
        return AssistanceLevel::Guided === $this->level;
    }

    /**
     * The guided entry (Saisie › guidée) is offered at G and B, hidden at A and 0; a teacher, who
     * prepares what the students will use, always has it.
     */
    public function opensGuidedEntry(bool $teacher): bool
    {
        return $teacher || $this->blocks();
    }

    /** An account number that subdivides no account of the reference chart is refused (G and B: « numéro hors PCG refusé »). */
    public function refusesAccountOutsideChart(): bool
    {
        return $this->blocks();
    }

    /** Such a number is at least signalled (G, B, A); level 0 says nothing. */
    public function signalsAccountOutsideChart(): bool
    {
        return $this->runsControls();
    }

    /** The employee's fiche requires what the DPAE declares - birth place, hour of hire (G and B: « champs obligatoires de la DPAE »). */
    public function requiresHiringDeclarationFields(): bool
    {
        return $this->blocks();
    }

    /** A simulated portal explains each motive of a rejection (G); elsewhere the motive alone is given, as the real portals do. */
    public function explainsPortalRejections(): bool
    {
        return AssistanceLevel::Guided === $this->level;
    }

    /** A receipt that differs from what its order left to receive is flagged (G: « écart de quantité signalé »). */
    public function flagsQuantityGaps(): bool
    {
        return AssistanceLevel::Guided === $this->level;
    }

    /**
     * A new stock movement shows, before it is recorded, the unit cost its article's next exit goes
     * out at - the CMUP, the month's, the oldest lot's (G: « valorisation calculée »); elsewhere the
     * student values the exit and reads the engine's value once it is recorded.
     */
    public function showsStockValuation(): bool
    {
        return AssistanceLevel::Guided === $this->level;
    }
}
