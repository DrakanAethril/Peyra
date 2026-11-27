<?php

declare(strict_types=1);

namespace App\Doctrine\Filter;

/** A DossierOwned row read with no dossier context (DossierFilter): a bug, never a request to read every dossier. */
final class DossierContextMissing extends \LogicException
{
}
