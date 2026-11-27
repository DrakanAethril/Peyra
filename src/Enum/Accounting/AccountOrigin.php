<?php

declare(strict_types=1);

namespace App\Enum\Accounting;

use App\Enum\Shared\BadgeTone;
use App\Enum\Shared\HasBadge;

/** Where an account of a dossier's plan comes from: the reference chart, a person, an imported file. */
enum AccountOrigin: string implements HasBadge
{
    case Reference = 'reference';
    case Created = 'created';
    case Imported = 'imported';

    public function label(): string
    {
        return match ($this) {
            self::Reference => 'Plan de référence',
            self::Created => 'Créé dans le dossier',
            self::Imported => 'Importé',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return self::Reference === $this ? BadgeTone::Neutral : BadgeTone::Info;
    }
}
