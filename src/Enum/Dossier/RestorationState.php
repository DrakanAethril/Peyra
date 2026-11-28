<?php

declare(strict_types=1);

namespace App\Enum\Dossier;

/**
 * A return of a dossier to one of its restore points: running (the dossier shows its waiting page),
 * done, or failed - the dossier then stays as it was before (design-ux.md § 4.9).
 */
enum RestorationState: string
{
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
}
