<?php

declare(strict_types=1);

namespace App\Service\Accounting\Fec\Import;

use App\Service\Shared\Money;

/** A line of an entry read from a FEC: its line in the file, the account, the third party's code in Peyra (empty: none), the label, one side. */
final readonly class FecImportLine
{
    public function __construct(
        public int $fecLine,
        public string $account,
        public string $thirdParty,
        public string $label,
        public Money $debit,
        public Money $credit,
    ) {
    }
}
