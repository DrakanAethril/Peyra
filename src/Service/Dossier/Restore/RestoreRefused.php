<?php

declare(strict_types=1);

namespace App\Service\Dossier\Restore;

/** A gesture on the restore points refused, with the French sentence that says why. */
final class RestoreRefused extends \RuntimeException
{
    /** The field the sentence belongs under, when it is one. */
    public function __construct(string $sentence, public readonly ?string $field = null)
    {
        parent::__construct($sentence);
    }
}
