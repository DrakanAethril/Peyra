<?php

declare(strict_types=1);

namespace App\Tests\Service\Shared\Background;

use App\Service\Shared\Background\WorkBudget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class WorkBudgetTest extends TestCase
{
    public function testMoreWorkStartsUntilTheBudgetIsSpent(): void
    {
        $clock = new MockClock('2026-11-28 10:00:00');
        $budget = WorkBudget::startingNow($clock);

        self::assertTrue($budget->allowsMore());
        $clock->sleep(11);
        self::assertTrue($budget->allowsMore(), 'Onze secondes : encore une copie.');
        $clock->sleep(1);
        self::assertFalse($budget->allowsMore(), 'Douze secondes : la requête s’arrête là.');
    }
}
