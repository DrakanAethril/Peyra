<?php

declare(strict_types=1);

namespace App\Service\Assets\Statement;

/**
 * A heading of the state (the annexe's: intangible, tangible, in progress, financial assets), its lines
 * and their total.
 */
final readonly class StatementGroup
{
    /** @param list<StatementRow> $rows */
    public function __construct(
        public string $key,
        public string $label,
        public array $rows,
        public StatementRow $total,
    ) {
    }
}
