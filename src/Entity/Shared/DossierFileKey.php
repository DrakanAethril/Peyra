<?php

declare(strict_types=1);

namespace App\Entity\Shared;

/**
 * Marks the column of a DossierOwned entity that holds the key of one of its dossier's files
 * (`dossiers/<id>/<category>/<random>.<ext>`, App\Service\Shared\Storage\DossierFiles). A copy of
 * the dossier copies the files under the new dossier's prefix and rewrites these columns with the
 * new keys (App\Service\Dossier\Copy\DossierCopier); the copier's completeness test refuses a
 * column that holds such a key without the mark.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class DossierFileKey
{
}
