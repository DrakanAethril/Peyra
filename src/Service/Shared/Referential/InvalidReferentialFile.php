<?php

declare(strict_types=1);

namespace App\Service\Shared\Referential;

/** The référentiel's file says something the code cannot trust; nothing of it is served. */
final class InvalidReferentialFile extends \RuntimeException
{
}
