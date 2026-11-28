<?php

declare(strict_types=1);

namespace App\Service\Dossier\Search;

/** A group of the global search and its lines, as drawn. */
final readonly class SearchSection
{
    /** @param non-empty-list<SearchItem> $items */
    public function __construct(
        public SearchGroup $group,
        public array $items,
    ) {
    }
}
