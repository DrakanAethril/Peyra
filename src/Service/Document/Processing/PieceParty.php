<?php

declare(strict_types=1);

namespace App\Service\Document\Processing;

use App\Entity\ThirdParty\ThirdParty;
use App\Enum\ThirdParty\ThirdPartyKind;

/**
 * The third party of a pièce as its entry names it: its kind (a supplier's credit note is a
 * purchase, a customer's a sale), what the grid's Compte cell holds for it - its auxiliary code
 * (« 401SCIE »), else its collective account's number with its code in the Tiers cell - and its
 * name, for the entry's label.
 */
final readonly class PieceParty
{
    public function __construct(
        public ThirdPartyKind $kind,
        public string $accountCell,
        public string $thirdPartyCell,
        public string $name,
    ) {
    }

    /** Null for a third party kept on no account: no entry line can name it. */
    public static function of(ThirdParty $thirdParty): ?self
    {
        $account = $thirdParty->getAccount();
        if (null === $account) {
            return null;
        }
        $auxiliary = $thirdParty->getAuxiliaryCode();

        return null === $auxiliary
            ? new self($thirdParty->getKind(), $account->getNumber(), $thirdParty->getCode(), $thirdParty->getName())
            : new self($thirdParty->getKind(), $auxiliary, '', $thirdParty->getName());
    }
}
