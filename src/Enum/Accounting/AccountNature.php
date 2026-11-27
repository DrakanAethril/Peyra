<?php

declare(strict_types=1);

namespace App\Enum\Accounting;

/**
 * What an account of the plan is, read from its number (PCG, art. 1121-1): never typed, so the
 * number alone says it. A journal of bank or cash needs a counterpart of its nature, a collective
 * account is a third parties' account, the account form shows it as a fact.
 */
enum AccountNature: string
{
    case Equity = 'equity';
    case Provision = 'provision';
    case Borrowing = 'borrowing';
    case FixedAsset = 'fixed_asset';
    case Depreciation = 'depreciation';
    case Inventory = 'inventory';
    case Supplier = 'supplier';
    case Customer = 'customer';
    case Employee = 'employee';
    case SocialBody = 'social_body';
    case State = 'state';
    case OtherThirdParty = 'other_third_party';
    case Bank = 'bank';
    case Cash = 'cash';
    case OtherFinancial = 'other_financial';
    case Expense = 'expense';
    case Revenue = 'revenue';
    case Commitment = 'commitment';

    /** The nature of an account number of the plan (« 512100 » is a bank, « 281540 » a depreciation). */
    public static function of(string $number): self
    {
        $two = substr($number, 0, 2);

        return match ($number[0] ?? '') {
            '1' => match ($two) {
                '15' => self::Provision,
                '16', '17', '18' => self::Borrowing,
                default => self::Equity,
            },
            '2' => \in_array($two, ['28', '29'], true) ? self::Depreciation : self::FixedAsset,
            '3' => '39' === $two ? self::Depreciation : self::Inventory,
            '4' => match ($two) {
                '40' => self::Supplier,
                '41' => self::Customer,
                '42' => self::Employee,
                '43' => self::SocialBody,
                '44' => self::State,
                '49' => self::Depreciation,
                default => self::OtherThirdParty,
            },
            '5' => match (true) {
                \in_array(substr($number, 0, 3), ['512', '514', '515', '516', '517'], true) => self::Bank,
                '53' === $two => self::Cash,
                '59' === $two => self::Depreciation,
                default => self::OtherFinancial,
            },
            '6' => self::Expense,
            '7' => self::Revenue,
            default => self::Commitment,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Equity => 'Capitaux propres',
            self::Provision => 'Provision',
            self::Borrowing => 'Emprunt et dette financière',
            self::FixedAsset => 'Immobilisation',
            self::Depreciation => 'Amortissement ou dépréciation',
            self::Inventory => 'Stock',
            self::Supplier => 'Fournisseur',
            self::Customer => 'Client',
            self::Employee => 'Personnel',
            self::SocialBody => 'Organisme social',
            self::State => 'État',
            self::OtherThirdParty => 'Autre tiers',
            self::Bank => 'Banque',
            self::Cash => 'Caisse',
            self::OtherFinancial => 'Autre compte financier',
            self::Expense => 'Charge',
            self::Revenue => 'Produit',
            self::Commitment => 'Engagement',
        };
    }
}
