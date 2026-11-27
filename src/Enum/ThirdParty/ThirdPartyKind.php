<?php

declare(strict_types=1);

namespace App\Enum\ThirdParty;

use App\Enum\Accounting\AccountNature;
use App\Enum\Shared\BadgeTone;
use App\Enum\Shared\HasBadge;

/**
 * What a third party is to the dossier (lot 1, step 1.2): a customer, a supplier, an employee or
 * anything else (a social body, the tax office, a partner, a lender). It says which accounts it may
 * be kept on, the letter its code starts with, and how the CSV names it.
 */
enum ThirdPartyKind: string implements HasBadge
{
    case Customer = 'customer';
    case Supplier = 'supplier';
    case Employee = 'employee';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Client',
            self::Supplier => 'Fournisseur',
            self::Employee => 'Salarié',
            self::Other => 'Autre tiers',
        };
    }

    /** The plural, for the filters of the list. */
    public function plural(): string
    {
        return match ($this) {
            self::Customer => 'Clients',
            self::Supplier => 'Fournisseurs',
            self::Employee => 'Salariés',
            self::Other => 'Autres tiers',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return self::Other === $this ? BadgeTone::Neutral : BadgeTone::Info;
    }

    /** The first letter of its code: C0003, F0001, S0001, O0001. */
    public function codePrefix(): string
    {
        return match ($this) {
            self::Customer => 'C',
            self::Supplier => 'F',
            self::Employee => 'S',
            self::Other => 'O',
        };
    }

    /** The word the CSV writes and reads back. */
    public function csvValue(): string
    {
        return match ($this) {
            self::Customer => 'client',
            self::Supplier => 'fournisseur',
            self::Employee => 'salarie',
            self::Other => 'autre',
        };
    }

    public static function fromCsv(string $folded): ?self
    {
        return match ($folded) {
            'client', 'clients', 'c' => self::Customer,
            'fournisseur', 'fournisseurs', 'f' => self::Supplier,
            'salarie', 'salaries', 'personnel', 's' => self::Employee,
            'autre', 'autres', 'autre tiers', 'o' => self::Other,
            default => null,
        };
    }

    /**
     * The natures of the accounts it may be kept on: a customer on a customers' account (41…), a
     * supplier on a suppliers' (40…), an employee on the staff's (42…); anything else on any other
     * third parties' account of class 4, or a borrowing (16…, a lender).
     *
     * @return list<AccountNature>
     */
    public function accountNatures(): array
    {
        return match ($this) {
            self::Customer => [AccountNature::Customer],
            self::Supplier => [AccountNature::Supplier],
            self::Employee => [AccountNature::Employee],
            self::Other => [AccountNature::Customer, AccountNature::Supplier, AccountNature::Employee, AccountNature::SocialBody, AccountNature::State, AccountNature::OtherThirdParty, AccountNature::Borrowing],
        };
    }

    /** The account a new third party of this kind is proposed on, when the plan holds it. */
    public function defaultAccountNumber(): ?string
    {
        return match ($this) {
            self::Customer => '411000',
            self::Supplier => '401000',
            self::Employee => '421000',
            self::Other => null,
        };
    }

    /** How a wrong account is told, under the field. */
    public function accountRule(): string
    {
        return match ($this) {
            self::Customer => 'Le compte d’un client est un compte de clients (411000, 416000…).',
            self::Supplier => 'Le compte d’un fournisseur est un compte de fournisseurs (401000, 404000…).',
            self::Employee => 'Le compte d’un salarié est un compte de personnel (421000…).',
            self::Other => 'Le compte d’un autre tiers est un compte de tiers de la classe 4 ou un emprunt (16…).',
        };
    }
}
