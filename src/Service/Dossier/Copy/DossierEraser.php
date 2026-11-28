<?php

declare(strict_types=1);

namespace App\Service\Dossier\Copy;

use App\Entity\Dossier\Dossier;
use App\Enum\Dossier\CopyKind;
use App\Service\Shared\Storage\DossierFiles;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The other half of the copier: empties a dossier of what a copy writes, or deletes it whole. A
 * dossier's tables point at one another with keys that refuse a deletion (a line names its account,
 * a journal its counterpart, a model its journal: lot 1, notes of steps 1.3, 1.4b and 1.4c), so a
 * `DELETE` of the dossier alone fails: the rows go table by table, in the reverse of the order the
 * copier writes them, a table's references to itself (an entry's reversal) set aside first.
 *
 * Only ever by a person's gesture - a template deleted, a dossier taken back to a restore point,
 * a restore point deleted, a distribution withdrawn: nothing is deleted automatically (CLAUDE.md). The audit trail is never
 * touched (it outlives the dossier). Like the copier, it writes through DBAL: entities of the
 * dossier loaded before are stale afterwards, which the caller knows.
 */
final readonly class DossierEraser
{
    public function __construct(
        private CopyPlan $plan,
        private Connection $connection,
        private EntityManagerInterface $entityManager,
        private DossierFiles $files,
    ) {
    }

    /**
     * Deletes every row and file a copy would bring back (CopyKind::Snapshot: the pending work
     * included), so that one can land: the dossier's own row, its members, its shares, the history
     * of its date stay. Answers the rows deleted, by table.
     *
     * @return array<string, int>
     */
    public function eraseContent(Dossier $dossier): array
    {
        $id = $dossier->getId() ?? throw new \LogicException('A saved dossier is erased.');

        return $this->connection->transactional(function () use ($id): array {
            $deleted = $this->delete($this->plan->tables(CopyKind::Snapshot), $id);
            $this->files->deleteAll($id);

            return $deleted;
        });
    }

    /**
     * Deletes the rows a copy would bring back, and leaves the files: a return to a restore point
     * erases and copies in one transaction, and a file deleted cannot be rolled back with it - its
     * caller deletes the files no row names any more once the transaction holds
     * (App\Service\Dossier\Restore\Restorations).
     *
     * @return array<string, int>
     */
    public function eraseRows(Dossier $dossier): array
    {
        $id = $dossier->getId() ?? throw new \LogicException('A saved dossier is erased.');

        return $this->connection->transactional(fn (): array => $this->delete($this->plan->tables(CopyKind::Snapshot), $id));
    }

    /**
     * Deletes the dossier: its restore points first (each a dossier of its own), its content, the
     * rows no copy carries, its files, then its own row - its members, shares and returns with it
     * (their keys cascade); a copy that named it as its template keeps its content and loses the
     * link.
     */
    public function deleteDossier(Dossier $dossier): void
    {
        $id = $dossier->getId() ?? throw new \LogicException('A saved dossier is deleted.');
        $snapshots = $this->connection->fetchFirstColumn('SELECT snapshot_id FROM restore_point WHERE dossier_id = ? ORDER BY id', [$id], [ParameterType::INTEGER]);
        foreach ($snapshots as $snapshotId) {
            $snapshot = $this->entityManager->find(Dossier::class, (int) $snapshotId);
            if ($snapshot instanceof Dossier) {
                $this->deleteDossier($snapshot);
            }
        }
        $this->connection->transactional(function () use ($dossier, $id): void {
            $this->delete($this->plan->ownedTables(), $id);
            $this->entityManager->remove($dossier);
            $this->entityManager->flush();
        });
        $this->files->deleteAll($id);
    }

    /**
     * @param list<CopiedTable> $tables in the copier's order
     *
     * @return array<string, int>
     */
    private function delete(array $tables, int $dossier): array
    {
        $quote = $this->connection->quoteSingleIdentifier(...);
        $deleted = [];
        foreach (array_reverse($tables) as $table) {
            foreach ($table->selfReferences as $column) {
                $this->connection->executeStatement(\sprintf('UPDATE %s SET %s = NULL WHERE dossier_id = ?', $quote($table->table), $quote($column)), [$dossier], [ParameterType::INTEGER]);
            }
            $deleted[$table->table] = (int) $this->connection->executeStatement(\sprintf('DELETE FROM %s WHERE dossier_id = ?', $quote($table->table)), [$dossier], [ParameterType::INTEGER]);
        }

        return $deleted;
    }
}
