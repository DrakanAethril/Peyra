<?php

declare(strict_types=1);

namespace App\Tests\Service\Accounting\Lettering;

use App\Service\Accounting\Lettering\LetteringRefused;
use App\Service\Accounting\Lettering\LetterSequence;
use PHPUnit\Framework\TestCase;

/** The lettering letters: A to Z, AA… - a bijective count in base 26, ranks reckoned by hand. */
final class LetterSequenceTest extends TestCase
{
    public function testRanksAndLettersGoBothWays(): void
    {
        // Z = 26, AA = 26 + 1, AZ = 26 + 26, BA = 2 × 26 + 1, ZZ = 26 × 26 + 26, AAA = 702 + 1.
        foreach (['A' => 1, 'Z' => 26, 'AA' => 27, 'AZ' => 52, 'BA' => 53, 'ZZ' => 702, 'AAA' => 703, 'ZZZZ' => 475_254] as $letter => $rank) {
            self::assertSame($rank, LetterSequence::ordinal($letter), $letter);
            self::assertSame($letter, LetterSequence::letter($rank), (string) $rank);
        }
        self::assertSame(27, LetterSequence::ordinal('aa'), 'The case is not the rank.');
    }

    public function testTheNextLetterFollowsTheHighestUsedWhateverItsCase(): void
    {
        self::assertSame('A', LetterSequence::next([]));
        self::assertSame('D', LetterSequence::next(['A', 'c', 'B']), 'A gap left by a délettrage stays a gap.');
        self::assertSame('AA', LetterSequence::next(['Z']));
        self::assertSame('BA', LetterSequence::next(['az']));
    }

    public function testAPartialLetterIsInSmallLetters(): void
    {
        self::assertTrue(LetterSequence::isPartial('b'));
        self::assertFalse(LetterSequence::isPartial('B'));
        self::assertTrue(LetterSequence::same('ab', 'AB'));
        self::assertFalse(LetterSequence::same('A', 'AA'));
    }

    public function testFourLettersAreTheEnd(): void
    {
        $this->expectException(LetteringRefused::class);
        LetterSequence::next(['ZZZZ']);
    }

    public function testAnythingElseIsNoLetter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LetterSequence::ordinal('A1');
    }
}
