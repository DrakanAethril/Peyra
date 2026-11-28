<?php

declare(strict_types=1);

namespace App\Enum\Banking;

use App\Enum\Accounting\AccountNature;
use App\Enum\ThirdParty\ThirdPartyKind;

/**
 * Which way a payment goes (step 2.6b; design-ux.md § 2.5: Ventes › Règlements, the receipts;
 * Achats › Règlements, the disbursements): from a customer into the bank or the till, or from
 * them to a supplier.
 */
enum PaymentDirection: string
{
    case Receipt = 'receipt';
    case Disbursement = 'disbursement';

    public function label(): string
    {
        return match ($this) {
            self::Receipt => 'Encaissement',
            self::Disbursement => 'Décaissement',
        };
    }

    /** The address's word: /payments/receipts, /payments/disbursements. */
    public function slug(): string
    {
        return match ($this) {
            self::Receipt => 'receipts',
            self::Disbursement => 'disbursements',
        };
    }

    public static function fromSlug(string $slug): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->slug() === $slug) {
                return $case;
            }
        }

        return null;
    }

    /** Who pays or is paid: a customer, a supplier. */
    public function thirdPartyKind(): ThirdPartyKind
    {
        return self::Receipt === $this ? ThirdPartyKind::Customer : ThirdPartyKind::Supplier;
    }

    /** The nature of the account its third party is kept on. */
    public function accountNature(): AccountNature
    {
        return self::Receipt === $this ? AccountNature::Customer : AccountNature::Supplier;
    }
}
