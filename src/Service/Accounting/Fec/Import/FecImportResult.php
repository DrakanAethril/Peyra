<?php

declare(strict_types=1);

namespace App\Service\Accounting\Fec\Import;

use App\Entity\Dossier\Dossier;

/** A template made from a FEC, and what it received. */
final readonly class FecImportResult
{
    public function __construct(
        public Dossier $template,
        public int $entries,
        public int $accountsCreated,
        public int $journals,
        public int $thirdParties,
        public int $letterings,
        public int $zeroLines,
        public int $lettersLeft,
    ) {
    }
}
