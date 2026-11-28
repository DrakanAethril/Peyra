<?php

declare(strict_types=1);

namespace App\Enum\Dossier;

use App\Enum\Shared\BadgeTone;

/**
 * What a profile may do in an area of the dossier, each level holding the ones below it: closed
 * (the area's screens are not offered), read, enter (drafts, pending work, lettering; the
 * settings' changes), validate (the engaging gesture: validate, reverse, close).
 */
enum AccessLevel: int
{
    case Closed = 0;
    case Read = 1;
    case Enter = 2;
    case Validate = 3;

    public function allows(self $wanted): bool
    {
        return $this->value >= $wanted->value;
    }

    /** « Saisie » becomes « Modification » for the settings, which are changed rather than entered. */
    public function labelIn(DossierArea $area): string
    {
        return match ($this) {
            self::Closed => 'Fermé',
            self::Read => 'Consultation',
            self::Enter => DossierArea::Settings === $area ? 'Modification' : 'Saisie',
            self::Validate => 'Validation',
        };
    }

    /** A right is a nature, not a state: never green (design-ui.md § 4.9). */
    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Closed => BadgeTone::Neutral,
            self::Read => BadgeTone::Neutral,
            self::Enter, self::Validate => BadgeTone::Info,
        };
    }
}
