<?php

declare(strict_types=1);

namespace App\Entity\Shared;

/**
 * A row that is written once and never changed nor deleted: a journal line, a dated regulatory
 * value. App\Doctrine\AppendOnlyGuard refuses any update or removal of one at flush time, whoever
 * asks; a correction is a new row.
 */
interface AppendOnly
{
}
