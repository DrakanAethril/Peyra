<?php

declare(strict_types=1);

namespace App\Tests\Service\Banking;

use App\Service\Banking\Reconciliation\ReconciliationItem;
use App\Service\Banking\Reconciliation\ReconciliationRules;
use App\Service\Shared\Money;
use PHPUnit\Framework\TestCase;

/**
 * The reconciliation's rules on the figures of mock-up 2 (operations-2026.md § 3.3): statement n° 11
 * of the Crédit Coopératif, 12 480,50 at the 01/11; the account 512100 at 10 530,10 before the
 * reconciliation, the statement at 13 658,10, the état falling right at 10 409,70 on both sides -
 * worked out by hand in the prepared data, not read from the code.
 */
final class ReconciliationRulesTest extends TestCase
{
    public function testTheEtatOfMockUpTwoFallsRight(): void
    {
        $report = ReconciliationRules::report(Money::cents(1_053_010), Money::cents(1_365_810), self::statement(), self::books(), Money::zero());

        // 10 530,10 − 96,40 − 24,00 = 10 409,70; 13 658,10 − 3 248,40 = 10 409,70.
        self::assertSame(-12_040, $report->toPostTotal()->cents);
        self::assertSame([4, 5], array_map(static fn (ReconciliationItem $item): int => $item->id, $report->toPost));
        self::assertSame([105], array_map(static fn (ReconciliationItem $item): int => $item->id, $report->notDebited()));
        self::assertSame([], $report->notCredited());
        self::assertSame(1_040_970, $report->rectifiedBooks()->cents);
        self::assertSame(1_040_970, $report->rectifiedBank()->cents);
        self::assertTrue($report->gap()->isZero());
        self::assertSame(3, $report->toDeal());
        self::assertSame([4, 6], [$report->pointedLines, $report->statementLines]);
        // Two lines neither pointed nor posted: what the validation is refused for at G and B.
        self::assertSame('2 lignes du relevé ne sont ni pointées ni comptabilisées (120,40).', ReconciliationRules::validationRefusal($report));
    }

    public function testOnceTheTwoLinesArePostedAndPointedNothingStandsAgainstTheValidation(): void
    {
        $statement = self::statement();
        $statement[3] = new ReconciliationItem(4, new \DateTimeImmutable('2026-11-14'), Money::cents(-9_640), 'E');
        $statement[4] = new ReconciliationItem(5, new \DateTimeImmutable('2026-11-18'), Money::cents(-2_400), 'F');
        $books = [...self::books(), new ReconciliationItem(106, new \DateTimeImmutable('2026-11-14'), Money::cents(-9_640), 'E'), new ReconciliationItem(107, new \DateTimeImmutable('2026-11-18'), Money::cents(-2_400), 'F')];

        // The two entries took the account to 10 409,70.
        $report = ReconciliationRules::report(Money::cents(1_040_970), Money::cents(1_365_810), $statement, $books, Money::zero());

        self::assertSame([], $report->toPost);
        self::assertSame(1_040_970, $report->rectifiedBooks()->cents);
        self::assertTrue($report->gap()->isZero());
        self::assertNull(ReconciliationRules::validationRefusal($report));
    }

    public function testAPointingWhoseEntryChangedIsReadAsBrokenAndItsMovementsAsOpen(): void
    {
        $books = self::books();
        // The draft pointed D was changed to 2 105,00 in the grid.
        $books[3] = new ReconciliationItem(104, new \DateTimeImmutable('2026-11-19'), Money::cents(210_500), 'D');
        $report = ReconciliationRules::report(Money::cents(1_048_510), Money::cents(1_365_810), self::statement(), $books, Money::zero());

        self::assertSame(['D'], $report->brokenLetters);
        self::assertSame([4, 5, 6], array_map(static fn (ReconciliationItem $item): int => $item->id, $report->toPost));
        self::assertSame([104, 105], array_map(static fn (ReconciliationItem $item): int => $item->id, $report->outstanding));
        self::assertFalse($report->isPointed($books[3]));
        self::assertStringStartsWith('Le pointage D ne tombe plus juste', (string) ReconciliationRules::validationRefusal($report));
    }

    public function testAStatementThatDoesNotAddUpLeavesAGap(): void
    {
        // The account carries 24,00 the statement never shows, and nothing explains it.
        $report = ReconciliationRules::report(Money::cents(1_050_610), Money::cents(1_365_810), self::statement(), self::books(), Money::zero());

        self::assertSame(-2_400, $report->gap()->cents);
        self::assertSame('L’état ne tombe pas juste : écart de 24,00.', ReconciliationRules::validationRefusal($report));
    }

    public function testWhatAnEarlierStatementLeftToPostCorrectsTheBooks(): void
    {
        $report = ReconciliationRules::report(Money::cents(1_053_010), Money::cents(1_365_810), self::statement(), self::books(), Money::cents(-800));

        self::assertSame(1_040_170, $report->rectifiedBooks()->cents);
        self::assertSame(-800, $report->gap()->cents);
    }

    public function testAPointingHoldsTwoMovementsThatMeetToTheCent(): void
    {
        self::assertStringStartsWith('Choisissez une ligne du relevé', (string) ReconciliationRules::pointingRefusal([Money::cents(-126_000)], []));
        self::assertNull(ReconciliationRules::pointingRefusal([Money::cents(-126_000)], [Money::cents(-126_000)]));
        // A remittance against the two cheques it holds: 1 430,00 + 720,00.
        self::assertNull(ReconciliationRules::pointingRefusal([Money::cents(215_000)], [Money::cents(143_000), Money::cents(72_000)]));
        // An entry and its reversal, without a statement line.
        self::assertNull(ReconciliationRules::pointingRefusal([], [Money::cents(-2_400), Money::cents(2_400)]));
        self::assertSame("La sélection ne tombe pas juste : \u{2212}\u{00A0}1\u{202F}260,00 au relevé, \u{2212}\u{00A0}1\u{202F}206,00 en comptabilité.", ReconciliationRules::pointingRefusal([Money::cents(-126_000)], [Money::cents(-120_600)]));
    }

    public function testProposalsPairTheSameAmountAtThreeDaysTheNearestOnce(): void
    {
        $day = static fn (string $date): \DateTimeImmutable => new \DateTimeImmutable($date);
        $statement = [
            new ReconciliationItem(1, $day('2026-11-09'), Money::cents(-126_000)),
            new ReconciliationItem(2, $day('2026-11-10'), Money::cents(-126_000)),
            new ReconciliationItem(3, $day('2026-11-20'), Money::cents(-50_000)),
            new ReconciliationItem(4, $day('2026-11-03'), Money::cents(432_000), 'A'),
        ];
        $books = [
            new ReconciliationItem(11, $day('2026-11-07'), Money::cents(-126_000)),
            new ReconciliationItem(12, $day('2026-11-08'), Money::cents(-126_000)),
            // Four days away: not proposed.
            new ReconciliationItem(13, $day('2026-11-16'), Money::cents(-50_000)),
            new ReconciliationItem(14, $day('2026-11-03'), Money::cents(432_000)),
        ];

        self::assertSame([['statement' => 1, 'entry' => 12], ['statement' => 2, 'entry' => 11]], ReconciliationRules::proposals($statement, $books));
    }

    /** @return list<ReconciliationItem> the statement of mock-up 2, its four pointings A to D */
    private static function statement(): array
    {
        $day = static fn (string $date): \DateTimeImmutable => new \DateTimeImmutable($date);

        return [
            new ReconciliationItem(1, $day('2026-11-03'), Money::cents(432_000), 'A'),
            new ReconciliationItem(2, $day('2026-11-05'), Money::cents(-391_200), 'B'),
            new ReconciliationItem(3, $day('2026-11-09'), Money::cents(-126_000), 'C'),
            new ReconciliationItem(4, $day('2026-11-14'), Money::cents(-9_640)),
            new ReconciliationItem(5, $day('2026-11-18'), Money::cents(-2_400)),
            new ReconciliationItem(6, $day('2026-11-20'), Money::cents(215_000), 'D'),
        ];
    }

    /** @return list<ReconciliationItem> the account 512100 in November, the cheque 7702219 not debited */
    private static function books(): array
    {
        $day = static fn (string $date): \DateTimeImmutable => new \DateTimeImmutable($date);

        return [
            new ReconciliationItem(101, $day('2026-11-03'), Money::cents(432_000), 'A'),
            new ReconciliationItem(102, $day('2026-11-05'), Money::cents(-391_200), 'B'),
            new ReconciliationItem(103, $day('2026-11-06'), Money::cents(-126_000), 'C'),
            new ReconciliationItem(104, $day('2026-11-19'), Money::cents(215_000), 'D'),
            new ReconciliationItem(105, $day('2026-11-28'), Money::cents(-324_840)),
        ];
    }
}
