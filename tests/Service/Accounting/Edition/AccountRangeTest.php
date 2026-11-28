<?php

declare(strict_types=1);

namespace App\Tests\Service\Accounting\Edition;

use App\Service\Accounting\Edition\AccountRange;
use PHPUnit\Framework\TestCase;

final class AccountRangeTest extends TestCase
{
    public function testEmptyBoundsReadTheWholePlan(): void
    {
        $range = AccountRange::read('', ' ');

        self::assertTrue($range->isAll());
        self::assertNull($range->describe());
        self::assertTrue($range->contains('101300'));
        self::assertTrue($range->contains('80000000'));
    }

    public function testAClassIsReadFromItsDigit(): void
    {
        $range = AccountRange::read('4', '4');

        self::assertTrue($range->contains('401000'));
        self::assertTrue($range->contains('491000'));
        self::assertFalse($range->contains('391000'));
        self::assertFalse($range->contains('512000'));
        self::assertSame('comptes 4', $range->describe());
    }

    public function testBoundsAreStartsOfNumbersBothIncluded(): void
    {
        $range = AccountRange::read('401', '411');

        self::assertTrue($range->contains('401000'));
        self::assertTrue($range->contains('40100010'));
        self::assertTrue($range->contains('408100'));
        self::assertTrue($range->contains('411999'));
        self::assertFalse($range->contains('400000'));
        self::assertFalse($range->contains('416000'));
        self::assertSame('comptes 401 à 411', $range->describe());
    }

    public function testAnOpenBound(): void
    {
        self::assertTrue(AccountRange::read('6', '')->contains('791000'));
        self::assertFalse(AccountRange::read('6', '')->contains('512000'));
        self::assertSame('comptes à partir de 6', AccountRange::read('6', '')->describe());
        self::assertTrue(AccountRange::read('', '5')->contains('101300'));
        self::assertFalse(AccountRange::read('', '5')->contains('601000'));
        self::assertSame('comptes jusqu’à 5', AccountRange::read('', '5')->describe());
    }

    public function testAFaultIsSaidAndTheWholePlanRead(): void
    {
        $letters = AccountRange::read('401SCIE', '');
        self::assertNotNull($letters->error);
        self::assertTrue($letters->isAll());

        $backwards = AccountRange::read('6', '4');
        self::assertSame('La sélection commence au compte 6, après sa fin, le compte 4.', $backwards->error);
        self::assertTrue($backwards->isAll());

        // 40 to 4: « 40 » is a start of « 4… », the range is not backwards.
        self::assertNull(AccountRange::read('40', '4')->error);
    }
}
