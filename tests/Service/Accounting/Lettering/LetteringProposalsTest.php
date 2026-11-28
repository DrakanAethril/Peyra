<?php

declare(strict_types=1);

namespace App\Tests\Service\Accounting\Lettering;

use App\Service\Accounting\Lettering\LetteringLine;
use App\Service\Accounting\Lettering\LetteringProposals;
use PHPUnit\Framework\TestCase;

/** The automatic lettering, by amount and by document, on lines written by hand. */
final class LetteringProposalsTest extends TestCase
{
    public function testByAmountPairsEqualAmountsOldestWithOldest(): void
    {
        $lines = [
            new LetteringLine(1, 0, 96_000, null, '2026-01-05', 'DEL-1'),
            new LetteringLine(2, 0, 96_000, null, '2026-01-02', 'DEL-2'),
            new LetteringLine(3, 96_000, 0, null, '2026-01-20', 'VIR-1'),
            new LetteringLine(4, 96_000, 0, null, '2026-01-10', 'VIR-2'),
            new LetteringLine(5, 0, 415_812, null, '2026-01-08', 'FA-1'),
            new LetteringLine(6, 200_000, 0, null, '2026-01-15', 'ACOMPTE'),
            new LetteringLine(7, 0, 50_000, 'A', '2026-01-01', 'X'),
            new LetteringLine(8, 50_000, 0, null, '2026-01-03', 'Y'),
        ];

        // 02/01 (2) with 10/01 (4), then 05/01 (1) with 20/01 (3); 415 812 and 200 000 find no twin;
        // the 500,00 debit's credit is already lettered.
        self::assertSame([[2, 4], [1, 3]], LetteringProposals::byAmount($lines));
    }

    public function testByDocumentTakesTheLinesOfADocumentThatBalance(): void
    {
        $lines = [
            new LetteringLine(1, 0, 415_812, null, '2026-01-08', 'FA-2601-0012'),
            new LetteringLine(2, 415_812, 0, null, '2026-01-25', 'fa-2601-0012 '),
            new LetteringLine(3, 0, 300_000, null, '2026-01-09', 'FA-2601-0020'),
            new LetteringLine(4, 100_000, 0, null, '2026-01-19', 'FA-2601-0020'),
            new LetteringLine(5, 0, 120_000, null, '2026-01-03', 'AN-2026-001'),
            new LetteringLine(6, 0, 80_000, null, '2026-01-03', 'AN-2026-001'),
            new LetteringLine(7, 0, 60_000, null, '2026-01-02', 'LOY-01'),
            new LetteringLine(8, 60_000, 0, null, '2026-01-04', 'LOY-01'),
        ];

        // FA-2601-0020 does not balance; AN-2026-001 holds credits only.
        self::assertSame([[7, 8], [1, 2]], LetteringProposals::byDocument($lines));
    }
}
