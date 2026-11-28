<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Dossier\Dossier;
use App\Enum\Dossier\CopyKind;
use App\Service\Dossier\Copy\CopyPlan;
use App\Service\Dossier\Copy\DossierCopier;
use App\Service\Shared\Storage\DossierFiles;
use Doctrine\DBAL\Connection;

/**
 * A dossier's content written so that two copies of it read the same (README of the plan, § 8):
 * every table the copier knows, its rows in id order, every column but `id` and `dossier_id`; a
 * reference into the dossier written « table#rank » (the row's place in its table), a file's key
 * without the dossier's prefix, a JSON column through its remapper with the ranks. Nothing comes
 * from the copier's own reading but the list of tables: a row it forgot, a column it did not
 * rewrite, reads differently.
 *
 * In a file of its own: ParaTest hands out files.
 */
final readonly class CanonicalDossierExport
{
    public function __construct(private Connection $connection, private CopyPlan $plan, private DossierCopier $copier)
    {
    }

    /** @return array<string, list<array<string, mixed>>> by table */
    public function of(Dossier $dossier, CopyKind $kind = CopyKind::Snapshot): array
    {
        $id = $dossier->getId() ?? throw new \LogicException('A saved dossier.');
        $tables = $this->plan->tables($kind);
        $rows = [];
        $ranks = [];
        foreach ($tables as $table) {
            $rows[$table->table] = $this->connection->fetchAllAssociative(\sprintf('SELECT * FROM %s WHERE dossier_id = ? ORDER BY id', $this->connection->quoteSingleIdentifier($table->table)), [$id]);
            foreach ($rows[$table->table] as $rank => $row) {
                $ranks[$table->table][self::id($row['id'])] = $rank + 1;
            }
        }
        $rankOf = static fn (string $table, int $rowId): ?int => $ranks[$table][$rowId] ?? null;
        $prefix = DossierFiles::prefix($id);

        $export = [];
        foreach ($tables as $table) {
            $export[$table->table] = [];
            foreach ($rows[$table->table] as $row) {
                unset($row['id'], $row['dossier_id']);
                foreach ($table->references as $column => $referenced) {
                    $row[$column] = null === $row[$column] ? null : $referenced.'#'.$rankOf($referenced, self::id($row[$column]));
                }
                foreach ($table->selfReferences as $column) {
                    $row[$column] = null === $row[$column] ? null : $table->table.'#'.$rankOf($table->table, self::id($row[$column]));
                }
                foreach ($table->fileKeys as $column) {
                    if (\is_string($row[$column]) && str_starts_with($row[$column], $prefix)) {
                        $row[$column] = '…/'.substr($row[$column], \strlen($prefix));
                    }
                }
                foreach ($table->columns as $column) {
                    $remapper = $this->copier->remapperOf($table->table, $column);
                    if (null !== $remapper && \is_string($row[$column])) {
                        $row[$column] = $remapper->remap($row[$column], $rankOf);
                    }
                }
                $export[$table->table][] = $row;
            }
        }

        return $export;
    }

    private static function id(mixed $value): int
    {
        return \is_int($value) ? $value : (\is_string($value) ? (int) $value : throw new \UnexpectedValueException('An id.'));
    }
}
