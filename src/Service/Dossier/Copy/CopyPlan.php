<?php

declare(strict_types=1);

namespace App\Service\Dossier\Copy;

use App\Entity\Accounting\PendingEntry;
use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierDateChange;
use App\Entity\Dossier\DossierOwned;
use App\Entity\Shared\DossierFileKey;
use App\Enum\Dossier\CopyKind;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ToOneOwningSideMapping;

/**
 * What a copy of a dossier copies, read from the Doctrine mapping of the DossierOwned entities
 * (README of the plan, § 8, « copie profonde complète »): every one of them, in the order their
 * references ask (a journal after the account it names, a line after its entry), but the two the
 * lists below name. A new entity is therefore copied without anybody touching the copier; a shape
 * the copier does not know (a many-to-many, a second reference to a dossier, an id that is not a
 * generated `id`) stops it here rather than being copied wrong, and the completeness test
 * (DossierCopierTest) fails until it is handled.
 *
 * The mapping does not change while the application runs: the plan is read once per process.
 */
final class CopyPlan
{
    /** DossierOwned entities a copy never carries, and why. Deleting a dossier deletes them. */
    public const array NOT_COPIED = [
        // The history of the dossier's date stays with the dossier: a copy starts its own, and
        // going back to a restore point leaves the date where the teacher put it.
        DossierDateChange::class => 'l’historique de la date reste au dossier',
    ];

    /** A person's work in progress: copied into a restore point and back, never handed out (CopyKind). */
    public const array PENDING_WORK = [PendingEntry::class];

    /** @var list<CopiedTable>|null every DossierOwned table, in dependency order */
    private ?array $tables = null;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * The tables a copy of this kind writes, each after the tables it points at.
     *
     * @return list<CopiedTable>
     */
    public function tables(CopyKind $kind): array
    {
        return array_values(array_filter(
            $this->ownedTables(),
            static fn (CopiedTable $table): bool => !\array_key_exists($table->entity, self::NOT_COPIED)
                && ($kind->keepsPendingWork() || !\in_array($table->entity, self::PENDING_WORK, true)),
        ));
    }

    /**
     * Every DossierOwned table, copied or not, each after the tables it points at: deleting goes
     * through them backwards.
     *
     * @return list<CopiedTable>
     */
    public function ownedTables(): array
    {
        return $this->tables ??= $this->read();
    }

    /** @return list<CopiedTable> */
    private function read(): array
    {
        $byEntity = [];
        foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass || !$metadata->getReflectionClass()->implementsInterface(DossierOwned::class)) {
                continue;
            }
            $byEntity[$metadata->getName()] = $metadata;
        }
        ksort($byEntity);

        $tables = [];
        foreach ($byEntity as $entity => $metadata) {
            $tables[$entity] = $this->table($metadata, $byEntity);
        }

        return $this->ordered($tables);
    }

    /**
     * @param ClassMetadata<object>                $metadata
     * @param array<string, ClassMetadata<object>> $owned
     */
    private function table(ClassMetadata $metadata, array $owned): CopiedTable
    {
        $entity = $metadata->getName();
        if (['id'] !== $metadata->getIdentifierColumnNames() || !$metadata->isIdGeneratorIdentity()) {
            throw new \LogicException(\sprintf('%s : le copieur attend un identifiant `id` généré par la base.', $entity));
        }

        $columns = [];
        $fileKeys = [];
        foreach ($metadata->fieldMappings as $field => $mapping) {
            if ('id' === $mapping->columnName) {
                continue;
            }
            $columns[] = $mapping->columnName;
            if (!str_contains($field, '.') && [] !== $metadata->getReflectionClass()->getProperty($field)->getAttributes(DossierFileKey::class)) {
                $fileKeys[] = $mapping->columnName;
            }
        }

        $references = [];
        $selfReferences = [];
        foreach ($metadata->getAssociationMappings() as $field => $mapping) {
            if (!$mapping->isOwningSide()) {
                continue;
            }
            if (!$mapping instanceof ToOneOwningSideMapping || 1 !== \count($mapping->joinColumns)) {
                throw new \LogicException(\sprintf('%s::$%s : le copieur ne sait copier qu’une référence à une seule colonne.', $entity, $field));
            }
            $column = $mapping->joinColumns[0]->name;
            $target = $mapping->targetEntity;
            if (Dossier::class === $target) {
                if ('dossier' !== $field) {
                    throw new \LogicException(\sprintf('%s::$%s : une ligne du dossier ne nomme pas un autre dossier.', $entity, $field));
                }
                continue;
            }
            $columns[] = $column;
            if ($target === $entity) {
                $selfReferences[] = $column;
            } elseif (isset($owned[$target])) {
                $references[$column] = $owned[$target]->getTableName();
            }
            // Any other target is global (a person): the copy names it as the original does.
        }

        return new CopiedTable($entity, $metadata->getTableName(), $columns, $references, $selfReferences, $fileKeys);
    }

    /**
     * Each table after those it points at, alphabetically among equals; a cycle stops here.
     *
     * @param array<string, CopiedTable> $tables by entity
     *
     * @return list<CopiedTable>
     */
    private function ordered(array $tables): array
    {
        $byTable = [];
        foreach ($tables as $table) {
            $byTable[$table->table] = $table;
        }
        $ordered = [];
        $placed = [];
        while (\count($ordered) < \count($byTable)) {
            $next = null;
            foreach ($byTable as $name => $table) {
                if (isset($placed[$name])) {
                    continue;
                }
                if ([] === array_diff(array_values($table->references), array_keys($placed))) {
                    $next = $table;
                    break;
                }
            }
            if (null === $next) {
                throw new \LogicException('Les tables du dossier se citent en boucle : le copieur ne sait pas dans quel ordre les écrire.');
            }
            $ordered[] = $next;
            $placed[$next->table] = true;
        }

        return $ordered;
    }
}
