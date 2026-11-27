<?php

declare(strict_types=1);

namespace App\Service\Accounting\Entry;

/** A save of the grid from a tab that read an older revision (design-ux.md § 6.5): refused. */
final class PendingStale extends \RuntimeException
{
    public function __construct(public readonly int $current)
    {
        parent::__construct('Cette saisie a été modifiée dans un autre onglet. Rechargez la page pour la reprendre.');
    }
}
