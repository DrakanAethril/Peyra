<?php

declare(strict_types=1);

namespace App\Service\Banking;

/**
 * A banking gesture refused (an import, a pointing, a validation…): nothing was written. `errors`
 * names the faults by field when a form has several (`file` for the file, `line.<n>` for its lines).
 */
final class BankingRefused extends \RuntimeException
{
    /** @param array<string, string> $errors */
    public function __construct(string $message, public readonly array $errors = [])
    {
        parent::__construct($message);
    }
}
