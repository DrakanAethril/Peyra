<?php

declare(strict_types=1);

namespace App\Service\Assets\Statement;

use App\Entity\Dossier\Exercise;

/**
 * The state of fixed assets and depreciation of an exercise, drawn up on a day (`at`: the dossier's
 * date, or the exercise's end once it is past): what FixedAssetStatements reads, for the screen, its
 * PDF and the annexe of the financial statements (step 3.6: the tables of fixed assets, depreciation
 * and impairments, group by group).
 */
final readonly class FixedAssetStatement
{
    /** @param list<StatementGroup> $groups */
    public function __construct(
        public Exercise $exercise,
        public \DateTimeImmutable $at,
        public array $groups,
        public StatementRow $total,
    ) {
    }

    public function group(string $key): ?StatementGroup
    {
        foreach ($this->groups as $group) {
            if ($group->key === $key) {
                return $group;
            }
        }

        return null;
    }

    public function isEmpty(): bool
    {
        return [] === $this->groups;
    }
}
