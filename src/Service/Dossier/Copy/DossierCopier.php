<?php

declare(strict_types=1);

namespace App\Service\Dossier\Copy;

use App\Entity\Dossier\Dossier;
use App\Enum\Dossier\CopyKind;
use App\Service\Shared\Storage\DossierFiles;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The one deep copy of a dossier (README of the plan, § 8): a template duplicated or distributed,
 * a restore point posed, a dossier taken back to one. It copies, into a dossier that holds
 * nothing yet, every row of the source's content that CopyPlan names for this kind of copy, the
 * company's fiche and plan's chart from the source's own row, and its files.
 *
 * Rows are copied table by table, each after the tables it points at, with one `INSERT … SELECT`
 * in the source's id order - so a copied row's id keeps its rank, which is how the old ids find
 * the new ones. A reference into the dossier is rewritten through a correspondence table kept for
 * the time of the copy; a reference into the same table (an entry's reversal) once every row is
 * in; a file's key with the key its copy got; a JSON column through its ColumnRemapper. A
 * reference outside the dossier (the author of a pending entry) is kept as it is.
 *
 * The copy writes through DBAL, around the ORM: the guards that keep a validated entry final
 * (LedgerGuard) watch what the application changes, and a copy changes nothing - it reproduces.
 * It goes around the dossier filter too, reading the source by its id alone; every caller has
 * been let into both dossiers by DossierVoter or is a command. One transaction: a copy that fails
 * leaves the target as empty as it was (the files already copied stay under its prefix, and go
 * with it when it is deleted).
 *
 * The temporary correspondence table is joined several times in one statement: MariaDB allows it
 * (MySQL does not), and production is MariaDB.
 */
final class DossierCopier
{
    private const string MAP = 'dossier_copy_map';
    private const int CHUNK = 500;

    /** @var array<string, ColumnRemapper> by « table.column » */
    private array $remappers = [];

    /** @param iterable<ColumnRemapper> $remappers */
    public function __construct(
        private readonly CopyPlan $plan,
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly DossierFiles $files,
        #[AutowireIterator(ColumnRemapper::TAG)]
        iterable $remappers,
    ) {
        foreach ($remappers as $remapper) {
            $this->remappers[$remapper->table().'.'.$remapper->column()] = $remapper;
        }
    }

    /** The remapper of a JSON column, if it has one (the completeness test reads the copies through it). */
    public function remapperOf(string $table, string $column): ?ColumnRemapper
    {
        return $this->remappers[$table.'.'.$column] ?? null;
    }

    public function copy(Dossier $from, Dossier $to, CopyKind $kind): CopyReport
    {
        $source = $from->getId();
        $target = $to->getId();
        if (null === $source || null === $target || $source === $target) {
            throw new \LogicException('A dossier is copied from a saved dossier into another saved one.');
        }
        $tables = $this->plan->tables($kind);

        return $this->connection->transactional(function () use ($from, $to, $source, $target, $tables): CopyReport {
            foreach ($tables as $table) {
                if ($this->count($table->table, $target) > 0) {
                    throw new \LogicException(\sprintf('The dossier %d already holds rows of %s: it is erased before a copy lands in it.', $target, $table->table));
                }
            }
            $to->takeContentOf($from);
            $this->entityManager->flush();

            $this->connection->executeStatement('CREATE TEMPORARY TABLE IF NOT EXISTS '.self::MAP.' (tbl VARCHAR(64) NOT NULL, old_id INT NOT NULL, new_id INT NOT NULL, PRIMARY KEY (tbl, old_id))');
            $this->connection->executeStatement('DELETE FROM '.self::MAP);
            try {
                $ids = [];
                $rows = [];
                foreach ($tables as $table) {
                    $ids[$table->table] = $this->insert($table, $source, $target);
                    $rows[$table->table] = \count($ids[$table->table]);
                }
                foreach ($tables as $table) {
                    $this->selfReferences($table, $source, $ids[$table->table]);
                }
                $files = $this->files->copyAll($source, $target);
                foreach ($tables as $table) {
                    $this->fileKeys($table, $target, $files);
                    $this->remapped($table, $target, $ids);
                }
            } finally {
                $this->connection->executeStatement('DROP TEMPORARY TABLE IF EXISTS '.self::MAP);
            }

            return new CopyReport($rows, \count($files));
        });
    }

    /**
     * Copies a table's rows of the source into the target and answers the new id of each old one.
     *
     * @return array<int, int> old id => new id
     */
    private function insert(CopiedTable $table, int $source, int $target): array
    {
        $quote = $this->connection->quoteSingleIdentifier(...);
        $columns = [$quote('dossier_id')];
        $values = [':target'];
        $joins = [];
        foreach ($table->columns as $index => $column) {
            $columns[] = $quote($column);
            if (\in_array($column, $table->selfReferences, true)) {
                $values[] = 'NULL';
            } elseif (isset($table->references[$column])) {
                $alias = 'm'.$index;
                $joins[] = \sprintf('LEFT JOIN %s %s ON %2$s.tbl = %s AND %2$s.old_id = s.%s', self::MAP, $alias, $this->connection->quote($table->references[$column]), $quote($column));
                $values[] = $alias.'.new_id';
            } else {
                $values[] = 's.'.$quote($column);
            }
        }
        $this->connection->executeStatement(
            \sprintf('INSERT INTO %s (%s) SELECT %s FROM %1$s s %s WHERE s.dossier_id = :source ORDER BY s.id', $quote($table->table), implode(', ', $columns), implode(', ', $values), implode(' ', $joins)),
            ['target' => $target, 'source' => $source],
            ['target' => ParameterType::INTEGER, 'source' => ParameterType::INTEGER],
        );

        $old = $this->ids($table->table, $source);
        $new = $this->ids($table->table, $target);
        if (\count($old) !== \count($new)) {
            throw new \LogicException(\sprintf('%s: %d rows read, %d written.', $table->table, \count($old), \count($new)));
        }
        $map = [] === $old ? [] : array_combine($old, $new);
        foreach (array_chunk($map, self::CHUNK, true) as $chunk) {
            $placeholders = [];
            $parameters = [];
            foreach ($chunk as $oldId => $newId) {
                $placeholders[] = '(?, ?, ?)';
                array_push($parameters, $table->table, $oldId, $newId);
            }
            $this->connection->executeStatement('INSERT INTO '.self::MAP.' (tbl, old_id, new_id) VALUES '.implode(', ', $placeholders), $parameters);
        }

        return $map;
    }

    /** @param array<int, int> $ids old id => new id */
    private function selfReferences(CopiedTable $table, int $source, array $ids): void
    {
        $quote = $this->connection->quoteSingleIdentifier(...);
        foreach ($table->selfReferences as $column) {
            $rows = $this->connection->fetchAllAssociative(
                \sprintf('SELECT id, %s AS ref FROM %s WHERE dossier_id = ? AND %1$s IS NOT NULL', $quote($column), $quote($table->table)),
                [$source],
                [ParameterType::INTEGER],
            );
            foreach ($rows as $row) {
                $this->connection->update($table->table, [$column => $ids[self::int($row['ref'])]], ['id' => $ids[self::int($row['id'])]]);
            }
        }
    }

    /** @param array<string, string> $files old key => new key */
    private function fileKeys(CopiedTable $table, int $target, array $files): void
    {
        $quote = $this->connection->quoteSingleIdentifier(...);
        foreach ($table->fileKeys as $column) {
            $rows = $this->connection->fetchAllAssociative(
                \sprintf('SELECT id, %s AS file_key FROM %s WHERE dossier_id = ? AND %1$s IS NOT NULL', $quote($column), $quote($table->table)),
                [$target],
                [ParameterType::INTEGER],
            );
            foreach ($rows as $row) {
                $key = \is_string($row['file_key']) ? $row['file_key'] : '';
                if (!isset($files[$key])) {
                    throw new \LogicException(\sprintf('%s.%s names the file "%s", which the dossier does not hold.', $table->table, $column, $key));
                }
                $this->connection->update($table->table, [$column => $files[$key]], ['id' => self::int($row['id'])]);
            }
        }
    }

    /** @param array<string, array<int, int>> $ids by table: old id => new id */
    private function remapped(CopiedTable $table, int $target, array $ids): void
    {
        $quote = $this->connection->quoteSingleIdentifier(...);
        foreach ($table->columns as $column) {
            $remapper = $this->remappers[$table->table.'.'.$column] ?? null;
            if (null === $remapper) {
                continue;
            }
            $idOf = static fn (string $referenced, int $id): ?int => $ids[$referenced][$id] ?? null;
            $rows = $this->connection->fetchAllAssociative(
                \sprintf('SELECT id, %s AS value FROM %s WHERE dossier_id = ? AND %1$s IS NOT NULL', $quote($column), $quote($table->table)),
                [$target],
                [ParameterType::INTEGER],
            );
            foreach ($rows as $row) {
                $value = \is_string($row['value']) ? $row['value'] : '';
                $remapped = $remapper->remap($value, $idOf);
                if ($remapped !== $value) {
                    $this->connection->update($table->table, [$column => $remapped], ['id' => self::int($row['id'])]);
                }
            }
        }
    }

    /** @return list<int> the ids of a dossier's rows in a table, in order */
    private function ids(string $table, int $dossier): array
    {
        return array_map(self::int(...), $this->connection->fetchFirstColumn(
            \sprintf('SELECT id FROM %s WHERE dossier_id = ? ORDER BY id', $this->connection->quoteSingleIdentifier($table)),
            [$dossier],
            [ParameterType::INTEGER],
        ));
    }

    private function count(string $table, int $dossier): int
    {
        return self::int($this->connection->fetchOne(
            \sprintf('SELECT COUNT(*) FROM %s WHERE dossier_id = ?', $this->connection->quoteSingleIdentifier($table)),
            [$dossier],
            [ParameterType::INTEGER],
        ));
    }

    private static function int(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }
        throw new \UnexpectedValueException('An id read from the database is an integer.');
    }
}
