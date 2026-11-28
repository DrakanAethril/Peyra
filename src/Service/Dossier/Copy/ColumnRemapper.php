<?php

declare(strict_types=1);

namespace App\Service\Dossier\Copy;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A column whose text names rows of the dossier by their ids - a JSON document the copier cannot
 * read by itself. The copier hands each copied value to its remapper with the way to find a row's
 * new id; the completeness test hands the same value with the row's rank, so that the two copies
 * compare. One service per such column.
 */
#[AutoconfigureTag(self::TAG)]
interface ColumnRemapper
{
    public const string TAG = 'peyra.dossier_column_remapper';

    public function table(): string;

    public function column(): string;

    /** @return list<string> the tables whose ids the column names */
    public function references(): array;

    /**
     * The value with every id it names replaced by `$idOf(table, id)`; an id `$idOf` answers null
     * for (a row that is not in the dossier any more) is dropped as the column's meaning allows.
     *
     * @param \Closure(string, int): ?int $idOf
     */
    public function remap(string $value, \Closure $idOf): string;
}
