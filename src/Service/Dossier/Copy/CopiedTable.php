<?php

declare(strict_types=1);

namespace App\Service\Dossier\Copy;

/**
 * One table of a dossier's content, as the copier reads its Doctrine mapping: the columns it
 * copies (every one but `id` and `dossier_id`), those that point at another row of the same
 * dossier (rewritten with the copied row's id), those that point at a row of the same table (set
 * once every row is in), and those that hold a file's key (rewritten with the copied file's).
 * Every name is the database's, unquoted.
 */
final readonly class CopiedTable
{
    /**
     * @param list<string>          $columns        every column but `id` and `dossier_id`
     * @param array<string, string> $references     column => the other table of the dossier it points at
     * @param list<string>          $selfReferences columns pointing at a row of this table
     * @param list<string>          $fileKeys       columns holding a file's key (App\Entity\Shared\DossierFileKey)
     */
    public function __construct(
        public string $entity,
        public string $table,
        public array $columns,
        public array $references,
        public array $selfReferences,
        public array $fileKeys,
    ) {
    }

    /** The columns copied as they are: neither a reference into the dossier nor a file's key. */
    public function isPlain(string $column): bool
    {
        return !isset($this->references[$column]) && !\in_array($column, $this->selfReferences, true) && !\in_array($column, $this->fileKeys, true);
    }
}
