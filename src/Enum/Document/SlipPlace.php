<?php

declare(strict_types=1);

namespace App\Enum\Document;

/**
 * Where an error slipped into a pièce shows (design-ux.md § 3.5): in its read data, in the entry
 * proposed, or in both - a value read wrong, and the entry that followed it.
 */
enum SlipPlace: string
{
    case Reading = 'reading';
    case Proposal = 'proposal';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::Reading => 'Données lues',
            self::Proposal => 'Écriture proposée',
            self::Both => 'Données lues et écriture',
        };
    }

    public function touchesReading(): bool
    {
        return self::Proposal !== $this;
    }

    public function touchesProposal(): bool
    {
        return self::Reading !== $this;
    }
}
