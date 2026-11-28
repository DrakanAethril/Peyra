<?php

declare(strict_types=1);

namespace App\Service\Accounting\Fec\Import;

use App\Enum\ThirdParty\ThirdPartyKind;

/**
 * A third party a FEC names by its CompAuxNum: its code in Peyra (« F0001 »), its auxiliary code
 * (the CompAuxNum, or one proposed when it is not one Peyra writes), its name (CompAuxLib), its kind
 * and its collective read from its first line, that line in the file.
 */
final readonly class FecImportThirdParty
{
    public function __construct(
        public string $code,
        public string $auxiliaryCode,
        public string $name,
        public ThirdPartyKind $kind,
        public string $account,
        public int $line,
    ) {
    }
}
