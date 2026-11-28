<?php

declare(strict_types=1);

namespace App\Enum\Banking;

use App\Enum\Shared\BadgeTone;
use App\Enum\Shared\HasBadge;

/**
 * Where a payment stands, read from it, never stored (Payment::state()): a cheque received waits
 * for its remittance to the bank, a bill sits in the portfolio until it is remitted for collection
 * or for discount; anything else is simply recorded.
 */
enum PaymentState: string implements HasBadge
{
    case Recorded = 'recorded';
    case ToDeposit = 'to_deposit';
    case Deposited = 'deposited';
    case InPortfolio = 'in_portfolio';
    case AtCollection = 'at_collection';
    case Discounted = 'discounted';

    public function label(): string
    {
        return match ($this) {
            self::Recorded => 'Enregistré',
            self::ToDeposit => 'À remettre',
            self::Deposited => 'Remis en banque',
            self::InPortfolio => 'En portefeuille',
            self::AtCollection => 'À l’encaissement',
            self::Discounted => 'Escompté',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::ToDeposit, self::InPortfolio => BadgeTone::Warning,
            self::AtCollection => BadgeTone::Info,
            self::Recorded, self::Deposited, self::Discounted => BadgeTone::Neutral,
        };
    }
}
