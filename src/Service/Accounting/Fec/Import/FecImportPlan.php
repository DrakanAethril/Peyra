<?php

declare(strict_types=1);

namespace App\Service\Accounting\Fec\Import;

use App\Service\Accounting\Fec\FecFinding;

/**
 * What a FEC makes of a template (FecImportReading): its accounts, journals, third parties and
 * entries in the order of the file, the groups of lines to letter (each line by its entry's and
 * its own index), and what is left aside - lines without an amount, letters whose other lines are
 * not in the file. A plan with findings writes nothing.
 */
final readonly class FecImportPlan
{
    /**
     * @param list<FecImportAccount>     $accounts
     * @param list<FecImportJournal>     $journals
     * @param list<FecImportThirdParty>  $thirdParties
     * @param list<FecImportEntry>       $entries
     * @param list<list<array{int, int}>> $letterings
     * @param list<FecFinding>           $findings
     */
    public function __construct(
        public array $accounts,
        public array $journals,
        public array $thirdParties,
        public array $entries,
        public array $letterings,
        public int $zeroLines,
        public int $lettersLeft,
        public array $findings,
    ) {
    }

    public function isApplicable(): bool
    {
        return [] === $this->findings;
    }
}
