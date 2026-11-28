<?php

declare(strict_types=1);

namespace App\Service\Accounting\Consultation;

/**
 * A validated entry's number as it is shown and typed, « ACH-2026-00012 » (Entry::getNumberLabel():
 * journal, exercise, number): read without case, the number's leading zeros optional
 * (« ach-2026-12 »). A pure reading; anything else is not a number.
 */
final readonly class EntryNumber
{
    private function __construct(
        public string $journal,
        public string $exercise,
        public int $number,
    ) {
    }

    public static function parse(string $typed): ?self
    {
        if (1 !== preg_match('/^([A-Z0-9]{1,6})-([A-Z0-9]{1,12})-(\d{1,9})$/', mb_strtoupper(trim($typed)), $match)) {
            return null;
        }
        $number = (int) $match[3];

        return $number > 0 ? new self($match[1], $match[2], $number) : null;
    }
}
