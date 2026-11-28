<?php

declare(strict_types=1);

namespace App\Enum\Dossier;

/**
 * What a copy of a dossier carries (App\Service\Dossier\Copy\DossierCopier).
 *
 * - **Template**: the dossier as it is handed out - a template duplicated, a template distributed
 *   to a student or a group. A person's work in progress (the entry grid's pending work) is
 *   theirs, not the dossier's: it stays behind.
 * - **Snapshot**: the dossier as it stands, pending work included - a restore point, and the way
 *   back from one (lot 1, note of step 1.4a).
 */
enum CopyKind: string
{
    case Template = 'template';
    case Snapshot = 'snapshot';

    public function keepsPendingWork(): bool
    {
        return self::Snapshot === $this;
    }
}
