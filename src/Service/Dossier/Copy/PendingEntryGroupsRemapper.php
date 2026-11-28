<?php

declare(strict_types=1);

namespace App\Service\Dossier\Copy;

use App\Entity\Accounting\PendingEntry;
use App\Service\Accounting\Entry\GridGroup;
use App\Service\Shared\JsonRequestPayload;

/**
 * The entry grid's pending work names the drafts it rewrites by their ids (GridGroup::$entry):
 * copied into a restore point and back, each names the copy of its draft. A draft that is not in
 * the dossier any more leaves a group that writes a new entry, as the grid does with it.
 */
final readonly class PendingEntryGroupsRemapper implements ColumnRemapper
{
    public function table(): string
    {
        return 'pending_entry';
    }

    public function column(): string
    {
        return 'typed_groups';
    }

    public function references(): array
    {
        return ['entry'];
    }

    public function remap(string $value, \Closure $idOf): string
    {
        $groups = [];
        foreach (JsonRequestPayload::listFromJson($value) as $payload) {
            $group = GridGroup::fromPayload($payload);
            $entry = null === $group->entry ? null : $idOf('entry', $group->entry);
            $groups[] = new GridGroup($group->key, $entry, $group->date, $group->document, $group->lines);
        }

        return PendingEntry::encodeGroups($groups);
    }
}
