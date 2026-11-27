<?php

declare(strict_types=1);

namespace App\Doctrine;

/** A flush that would break an accounting invariant (LedgerGuard): a programming error, never a person's. */
final class LedgerRefused extends \LogicException
{
}
