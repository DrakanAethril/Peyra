<?php

declare(strict_types=1);

namespace App\Tests\Service\Accounting\Consultation;

use App\Service\Accounting\Consultation\ConsultationWindow;
use PHPUnit\Framework\TestCase;

/** The days a consultation reads: the defaults, a window typed, the faults said and the defaults kept. */
final class ConsultationWindowTest extends TestCase
{
    public function testEmptyBoundsAreTheExerciseUpToTheDossiersDate(): void
    {
        $window = ConsultationWindow::read('', '', self::day('2026-01-01'), self::day('2026-03-15'));

        self::assertSame(['2026-01-01', '2026-03-15', null], self::of($window));
        self::assertTrue($window->isDefault(self::day('2026-01-01'), self::day('2026-03-15')));
    }

    public function testTheBoundsTypedAreRead(): void
    {
        $window = ConsultationWindow::read('2026-02-01', '2026-02-28', self::day('2026-01-01'), self::day('2026-03-15'));

        self::assertSame(['2026-02-01', '2026-02-28', null], self::of($window));
        self::assertFalse($window->isDefault(self::day('2026-01-01'), self::day('2026-03-15')));
        // One bound only: the other is the default.
        self::assertSame(['2026-01-01', '2026-01-31', null], self::of(ConsultationWindow::read('', '2026-01-31', self::day('2026-01-01'), self::day('2026-03-15'))));
    }

    public function testAWindowThatEndsBeforeItStartsIsSaidAndTheDefaultsRead(): void
    {
        $window = ConsultationWindow::read('2026-03-01', '2026-02-01', self::day('2026-01-01'), self::day('2026-03-15'));

        self::assertSame(['2026-01-01', '2026-03-15', 'La période commence le 01/03/2026, après sa fin, le 01/02/2026.'], self::of($window));
    }

    public function testABoundThatIsNotADayIsSaid(): void
    {
        $window = ConsultationWindow::read('2026-02-31', '', self::day('2026-01-01'), self::day('2026-03-15'));

        self::assertSame(['2026-01-01', '2026-03-15', 'Une date de la période doit s’écrire jj/mm/aaaa, par exemple 31/01/2026.'], self::of($window));
    }

    public function testAWindowOfOneDayIsAWindow(): void
    {
        self::assertSame(['2026-01-31', '2026-01-31', null], self::of(ConsultationWindow::of(self::day('2026-01-31'), self::day('2026-01-31'))));

        $this->expectException(\InvalidArgumentException::class);
        ConsultationWindow::of(self::day('2026-02-01'), self::day('2026-01-31'));
    }

    /** @return array{string, string, ?string} */
    private static function of(ConsultationWindow $window): array
    {
        return [$window->from->format('Y-m-d'), $window->to->format('Y-m-d'), $window->error];
    }

    private static function day(string $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day);
    }
}
