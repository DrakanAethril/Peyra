<?php

declare(strict_types=1);

namespace App\Enum\Payroll;

use App\Enum\Shared\BadgeTone;
use App\Enum\Shared\HasBadge;

/**
 * Where a payment of the échéancier social stands at the dossier's date (step 4.6): its pièce stands
 * in the books (paid), else it is to pay until its due date and late after it. Green is for the paid
 * state alone.
 */
enum SocialDueState: string implements HasBadge
{
    case Paid = 'paid';
    case Due = 'due';
    case Late = 'late';

    /** Pure: paid when its payment's pièce stands, else late once the dossier's date has passed the due date. */
    public static function at(bool $paid, \DateTimeImmutable $dueOn, \DateTimeImmutable $dossierDate): self
    {
        return match (true) {
            $paid => self::Paid,
            $dossierDate > $dueOn => self::Late,
            default => self::Due,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Payée',
            self::Due => 'À payer',
            self::Late => 'En retard',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Paid => BadgeTone::Success,
            self::Due => BadgeTone::Neutral,
            self::Late => BadgeTone::Danger,
        };
    }
}
