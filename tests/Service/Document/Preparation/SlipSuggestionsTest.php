<?php

declare(strict_types=1);

namespace App\Tests\Service\Document\Preparation;

use App\Service\Document\Preparation\SlipSuggestions;
use PHPUnit\Framework\TestCase;

/**
 * The plausible wrong values of « Glisser une erreur » (step 2.2b), worked out by hand.
 */
final class SlipSuggestionsTest extends TestCase
{
    public function testAnAmount(): void
    {
        // 2 586,00 TTC: the HT 2 155,00; units and tens of euros swapped, 2 568,00; the cents equal
        // (00), raised by ten cents, 2 586,10.
        self::assertSame([215_500, 256_800, 258_610], SlipSuggestions::amounts(258_600, 215_500));
        // 431,00 VAT, no counterpart: 413,00; 431,10; tens and hundreds swapped, 341,00.
        self::assertSame([41_300, 43_110, 34_100], SlipSuggestions::amounts(43_100, null));
    }

    public function testARate(): void
    {
        self::assertSame(['10', '5.5', '2.1'], SlipSuggestions::rates('20', ['20', '10', '5.5', '2.1']));
        self::assertSame(['20', '10', '2.1'], SlipSuggestions::rates('5.5', ['20', '10', '5.5', '2.1']));
        self::assertSame([], SlipSuggestions::rates('20', []), 'No rate installed: the teacher types one.');
    }

    public function testAnAccountsNeighbours(): void
    {
        $plan = ['401000', '601000', '602000', '606100', '607000', '611000', '6011000'];
        // Same two first digits first, nearest number first: 6011000 reads 60110000 against 60100000.
        self::assertSame(['6011000', '602000', '606100'], SlipSuggestions::accounts('601000', $plan));
        // Nothing else under 61: the class's nearest.
        self::assertSame(['607000', '606100', '602000'], SlipSuggestions::accounts('611000', $plan));
    }

    public function testAThirdPartyOfANearName(): void
    {
        $names = ['F0001' => 'Scierie Dupont', 'F0002' => 'Scierie Durand', 'F0003' => 'Scierie Dupond', 'F0004' => 'Vernis du Lyonnais'];
        // « scierie dupond » is one letter away, « scierie durand » three.
        self::assertSame(['F0003', 'F0002'], SlipSuggestions::thirdParties('F0001', $names, 2));
        self::assertSame(['F0003', 'F0002', 'F0004'], SlipSuggestions::thirdParties('F0001', $names));
    }
}
