<?php

declare(strict_types=1);

namespace App\Service\Banking\Statement;

use App\Service\Shared\Money;

/**
 * A line the statement generator may print (step 2.6b): a movement of the bank account in the books
 * (`key` « l<line id> ») or the bank's credit of a bill remitted for collection at its due date
 * (« b<payment id> »). Amounts as the bank sees them: positive, a credit to the account.
 */
final readonly class StatementRow
{
    public function __construct(
        public string $key,
        public \DateTimeImmutable $date,
        public string $label,
        public Money $amount,
        /** Where it comes from: « BQ1-2026-00031 · REM-0008 », « Effet LCR-0012 ». */
        public string $origin,
    ) {
    }
}
