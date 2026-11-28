<?php

declare(strict_types=1);

namespace App\Tests\Service\Assets\Depreciation;

use App\Enum\Assets\DepreciationMethod;
use App\Service\Assets\Depreciation\DayCount;
use App\Service\Assets\Depreciation\DepreciationPlan;
use App\Service\Assets\Depreciation\PlanInput;
use App\Service\Assets\Depreciation\PlanRow;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

/**
 * Depreciation plans worked out by hand (step 3.4): the demonstration's assets
 * (donnees/demo/immobilisations.yaml) and a few cases of their own. Every expected amount below is
 * computed in its comment, never read from the code.
 */
final class DepreciationPlanTest extends TestCase
{
    public function testDaysAreCountedOnAYearOf360(): void
    {
        // 15 January → 31 December: 16 days in January (30 − 15 + 1), then 11 months of 30.
        self::assertSame(346, DayCount::days(new \DateTimeImmutable('2024-01-15'), new \DateTimeImmutable('2024-12-31')));
        self::assertSame(360, DayCount::days(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31')));
        // February ends its month like any other.
        self::assertSame(30, DayCount::days(new \DateTimeImmutable('2026-02-01'), new \DateTimeImmutable('2026-02-28')));
        self::assertSame(180, DayCount::days(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-06-30')));
        self::assertSame(1, DayCount::days(new \DateTimeImmutable('2026-01-31'), new \DateTimeImmutable('2026-01-31')));
        self::assertSame(0, DayCount::days(new \DateTimeImmutable('2026-02-01'), new \DateTimeImmutable('2026-01-31')));
        self::assertSame(4, DayCount::months(new \DateTimeImmutable('2021-09-01'), new \DateTimeImmutable('2021-12-31')));
    }

    public function testLinearProrataTemporisFromTheDayOfPuttingIntoService(): void
    {
        // Plaqueuse de chants: 26 000,00 over 7 years from 01/10/2024. 26 000 / 7 = 3 714,2857 a year.
        // 2024: 90 days → 3 714,2857 × 90 / 360 = 928,57; 2025 to 2030: 3 714,29;
        // 2031: what is left, 26 000,00 − 928,57 − 6 × 3 714,29 = 2 785,69.
        $rows = DepreciationPlan::rows($this->linear('26000.00', '2024-10-01', 7, 2024, 2032));

        self::assertSame([2024 => 92_857, 2025 => 371_429, 2026 => 371_429, 2027 => 371_429, 2028 => 371_429, 2029 => 371_429, 2030 => 371_429, 2031 => 278_569], $this->dotations($rows));
        self::assertSame([90, 360], [$rows[0]->counted, $rows[1]->counted]);
        self::assertSame(0, end($rows)->netValue->cents);
        self::assertSame(2_600_000, end($rows)->closing->cents);
    }

    public function testLinearFromTheMiddleOfAMonth(): void
    {
        // Matériel informatique: 4 200,00 over 3 years from 15/01/2024. 2024: 346 days →
        // 1 400 × 346 / 360 = 1 345,555… → 1 345,56; 2025, 2026: 1 400,00; 2027: 4 200 − 4 145,56 = 54,44.
        $rows = DepreciationPlan::rows($this->linear('4200.00', '2024-01-15', 3, 2024, 2028));

        self::assertSame([2024 => 134_556, 2025 => 140_000, 2026 => 140_000, 2027 => 5_444], $this->dotations($rows));
    }

    public function testLinearDownToAResidualValue(): void
    {
        // 10 000,00 with a residual value of 1 000,00, over 4 years from 01/07/2026: base 9 000,00,
        // 2 250,00 a year; 2026: 180 days → 1 125,00; 2027 to 2029: 2 250,00; 2030: 9 000 − 7 875 = 1 125,00.
        $input = new PlanInput(DepreciationMethod::Linear, Money::euros('10000.00'), Money::euros('1000.00'), new \DateTimeImmutable('2026-07-01'), new \DateTimeImmutable('2026-07-01'), $this->years(2026, 2031), 4);
        $rows = DepreciationPlan::rows($input);

        self::assertSame([2026 => 112_500, 2027 => 225_000, 2028 => 225_000, 2029 => 225_000, 2030 => 112_500], $this->dotations($rows));
        self::assertSame(100_000, end($rows)->netValue->cents, 'the net value ends on the residual value');
    }

    public function testADisposalInTheExerciseStopsThePlanOnItsDay(): void
    {
        // Fourgonnette: 21 500,00 over 5 years from 01/02/2022, sold on 30/06/2026. 4 300,00 a year;
        // 2022: 330 days → 3 941,67; 2023 to 2025: 4 300,00; 2026: 180 days → 2 150,00.
        // Cumul 18 991,67, net value 2 508,33.
        $input = new PlanInput(DepreciationMethod::Linear, Money::euros('21500.00'), Money::zero(), new \DateTimeImmutable('2022-02-01'), new \DateTimeImmutable('2022-02-01'), $this->years(2022, 2028), 5, disposedOn: new \DateTimeImmutable('2026-06-30'));
        $rows = DepreciationPlan::rows($input);

        self::assertSame([2022 => 394_167, 2023 => 430_000, 2024 => 430_000, 2025 => 430_000, 2026 => 215_000], $this->dotations($rows));
        self::assertSame([1_899_167, 250_833], [end($rows)->closing->cents, end($rows)->netValue->cents]);
    }

    public function testDecliningSwitchesToLinear(): void
    {
        // Raboteuse: 12 400,00 over 5 years, coefficient 1,75: rate 35 %, from 01/09/2021 (acquired the 15th).
        // 2021: 4 months → 12 400 × 35 % × 4 / 12 = 1 446,67; 2022: 10 953,33 × 35 % = 3 833,67;
        // 2023: 7 119,66 × 35 % = 2 491,88 (12 / 44 months left = 27,3 % < 35 %);
        // 2024: 32 months left, 12 / 32 = 37,5 % ≥ 35 %: 4 627,78 × 12 / 32 = 1 735,42; 2025: the same;
        // 2026: what is left, 4 627,78 − 3 470,84 = 1 156,94 (8 months).
        $input = new PlanInput(DepreciationMethod::Declining, Money::euros('12400.00'), Money::zero(), new \DateTimeImmutable('2021-09-15'), new \DateTimeImmutable('2021-09-15'), $this->years(2021, 2027), 5, BigDecimal::of('1.75'));
        $rows = DepreciationPlan::rows($input);

        self::assertSame([2021 => 144_667, 2022 => 383_367, 2023 => 249_188, 2024 => 173_542, 2025 => 173_542, 2026 => 115_694], $this->dotations($rows));
        self::assertSame([false, false, false, true, true, true], array_map(static fn (PlanRow $row): bool => $row->switched, $rows));
        self::assertSame([4, 12, 12, 12, 12, 8], array_map(static fn (PlanRow $row): int => $row->counted, $rows));
    }

    public function testDecliningOnFourYearsSwitchesInItsSecondYear(): void
    {
        // 10 000,00 over 4 years, coefficient 1,25: rate 31,25 %, acquired in January 2026.
        // 2026: 3 125,00; 2027: 36 months left, 12 / 36 = 33,3 % ≥ 31,25 %: 6 875,00 / 3 = 2 291,67;
        // 2028: 2 291,67; 2029: 6 875,00 − 4 583,34 = 2 291,66.
        $input = new PlanInput(DepreciationMethod::Declining, Money::euros('10000.00'), Money::zero(), new \DateTimeImmutable('2026-01-10'), new \DateTimeImmutable('2026-02-01'), $this->years(2026, 2030), 4, BigDecimal::of('1.25'));

        self::assertSame([2026 => 312_500, 2027 => 229_167, 2028 => 229_167, 2029 => 229_166], $this->dotations(DepreciationPlan::rows($input)));
    }

    public function testUnitsOfWork(): void
    {
        // 50 000,00 for 100 000 units: 2026, 18 000 units → 9 000,00; 2027, 25 000 → 12 500,00;
        // 2028 not known yet: the plan stops there.
        $windows = $this->years(2026, 2030);
        $input = new PlanInput(DepreciationMethod::Units, Money::euros('50000.00'), Money::zero(), new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-03-01'), $windows, totalUnits: 100_000, units: ['2026-12-31' => 18_000, '2027-12-31' => 25_000]);
        self::assertSame([2026 => 900_000, 2027 => 1_250_000], $this->dotations(DepreciationPlan::rows($input)));

        // 2028, 60 000 units: 103 000 reached, the 28 500,00 left - never more than the base.
        $input = new PlanInput(DepreciationMethod::Units, Money::euros('50000.00'), Money::zero(), new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-03-01'), $windows, totalUnits: 100_000, units: ['2026-12-31' => 18_000, '2027-12-31' => 25_000, '2028-12-31' => 60_000, '2029-12-31' => 5_000]);
        self::assertSame([2026 => 900_000, 2027 => 1_250_000, 2028 => 2_850_000], $this->dotations(DepreciationPlan::rows($input)));
    }

    public function testAFiscalDecliningPlanBesideALinearOneGivesTheDerogatory(): void
    {
        // Raboteuse in the books: linear over 5 years from 15/09/2021 - 2021: 106 days → 2 480 × 106 / 360
        // = 730,22; 2022 to 2025: 2 480,00; 2026: 12 400 − 10 650,22 = 1 749,78. Fiscal: the declining plan
        // above. Dérogatoire: 2021 +716,45, 2022 +1 353,67, 2023 +11,88, 2024 −744,58, 2025 −744,58,
        // 2026 −592,84 (what is left of 592,84 = 716,45 + 1 353,67 + 11,88 − 2 × 744,58).
        $input = new PlanInput(DepreciationMethod::Linear, Money::euros('12400.00'), Money::zero(), new \DateTimeImmutable('2021-09-15'), new \DateTimeImmutable('2021-09-15'), $this->years(2021, 2027), 5, BigDecimal::of('1.75'), fiscalDeclining: true);
        $rows = DepreciationPlan::rows($input);

        self::assertSame([2021 => 73_022, 2022 => 248_000, 2023 => 248_000, 2024 => 248_000, 2025 => 248_000, 2026 => 174_978], $this->dotations($rows));
        self::assertSame([71_645, 135_367, 1_188, -74_458, -74_458, -59_284], array_map(static fn (PlanRow $row): ?int => $row->derogatory?->cents, $rows));
        self::assertSame([71_645, 207_012, 208_200, 133_742, 59_284, 0], array_map(static fn (PlanRow $row): ?int => $row->derogatoryBalance?->cents, $rows));
    }

    public function testComponentsAreAddedUpExerciseByExercise(): void
    {
        // Centre d'usinage in service on 01/04/2026: structure 74 500,00 over 10 years (7 450,00 a year;
        // 2026: 270 days → 5 587,50), spindle 12 000,00 over 5 years (2 400,00; 2026: 1 800,00; 2031: 600,00).
        // 2026: 7 387,50; 2031: 7 450,00 + 600,00 = 8 050,00; 2032: the structure alone, 7 450,00.
        $structure = DepreciationPlan::rows($this->linear('74500.00', '2026-04-01', 10, 2026, 2037));
        $spindle = DepreciationPlan::rows($this->linear('12000.00', '2026-04-01', 5, 2026, 2037));
        $rows = DepreciationPlan::combine([$structure, $spindle]);

        $dotations = $this->dotations($rows);
        self::assertSame([738_750, 985_000, 805_000, 745_000], [$dotations[2026], $dotations[2027], $dotations[2031], $dotations[2032]]);
        self::assertCount(11, $rows, '2026 to 2036');
        // End of 2032: the structure 74 500 − 5 587,50 − 6 × 7 450 = 24 212,50, the spindle 0.
        self::assertSame(2_421_250, $rows[6]->netValue->cents);
        self::assertSame(0, end($rows)->netValue->cents);
    }

    public function testAnAssetNotDepreciatedHasNoPlan(): void
    {
        $input = new PlanInput(DepreciationMethod::None, Money::euros('38000.00'), Money::zero(), new \DateTimeImmutable('1994-03-14'), new \DateTimeImmutable('1994-03-14'), $this->years(2025, 2027));

        self::assertSame([], DepreciationPlan::rows($input));
    }

    public function testAnImpairmentSpreadsWhatIsLeftOverTheTimeLeft(): void
    {
        // 10 000,00 over 5 years from 01/01/2024: 2 000,00 in 2024 and 2025, net 6 000,00 at 31/12/2025.
        // Impaired by 1 200,00 then: 4 800,00 left over the 3 years left (1 080 days) - 2026: 4 800 × 360 /
        // 1 080 = 1 600,00; 2027: (4 800 − 1 600) × 360 / 720 = 1 600,00; 2028, the last: 1 600,00.
        $input = new PlanInput(DepreciationMethod::Linear, Money::euros('10000.00'), Money::zero(), new \DateTimeImmutable('2024-01-01'), new \DateTimeImmutable('2024-01-01'), $this->years(2024, 2029), 5, impairments: ['2025-12-31' => Money::euros('1200.00')]);
        $rows = DepreciationPlan::rows($input);

        self::assertSame([2024 => 200_000, 2025 => 200_000, 2026 => 160_000, 2027 => 160_000, 2028 => 160_000], $this->dotations($rows));
        // Net of it from its exercise on: 10 000 − 4 000 − 1 200 = 4 800 at 31/12/2025; 3 200 at 31/12/2026.
        self::assertSame([800_000, 480_000, 320_000], [$rows[0]->netValue->cents, $rows[1]->netValue->cents, $rows[2]->netValue->cents]);
        self::assertNull($rows[0]->impairment);
        self::assertSame(120_000, $rows[1]->impairment?->cents);
        self::assertSame(0, end($rows)->netValue->cents);
    }

    public function testADisposalStopsThePlanOnItsDay(): void
    {
        // The van I07: 21 500,00 over 5 years from 01/02/2022, sold on 30/06/2026: 2026 counts 180 days,
        // 21 500 × 180 / 1 800 = 2 150,00, and nothing after.
        $input = new PlanInput(DepreciationMethod::Linear, Money::euros('21500.00'), Money::zero(), new \DateTimeImmutable('2022-02-01'), new \DateTimeImmutable('2022-02-01'), $this->years(2022, 2027), 5, disposedOn: new \DateTimeImmutable('2026-06-30'));
        $rows = DepreciationPlan::rows($input);

        self::assertSame([2022 => 394_167, 2023 => 430_000, 2024 => 430_000, 2025 => 430_000, 2026 => 215_000], $this->dotations($rows));
        self::assertSame(250_833, end($rows)->netValue->cents);
    }

    public function testAPlanStoppedInsideItsLastExercise(): void
    {
        // The planer I05 up to 31/03/2026 (its plan ends on 14/09/2026): 12 400,00 × 90 / 1 800 = 620,00, not
        // what is left. Its fiscal plan, switched to linear in 2024 on 12 400 − 1 446,67 − 3 833,67 − 2 491,88
        // = 4 627,78 over 32 months: 3 months, 4 627,78 × 3 / 32 = 433,854 → 433,85; the dérogatoire is
        // taken back by 620,00 − 433,85 = 186,15.
        $input = new PlanInput(DepreciationMethod::Linear, Money::euros('12400.00'), Money::zero(), new \DateTimeImmutable('2021-09-15'), new \DateTimeImmutable('2021-09-15'), $this->years(2021, 2026), 5, BigDecimal::of('1.75'), disposedOn: new \DateTimeImmutable('2026-03-31'), fiscalDeclining: true);
        $last = DepreciationPlan::rows($input)[5];

        self::assertSame([62_000, 43_385, -18_615], [$last->dotation->cents, $last->fiscal?->cents, $last->derogatory?->cents]);
    }

    private function linear(string $cost, string $from, int $years, int $first, int $last): PlanInput
    {
        return new PlanInput(DepreciationMethod::Linear, Money::euros($cost), Money::zero(), new \DateTimeImmutable($from), new \DateTimeImmutable($from), $this->years($first, $last), $years);
    }

    /** @return list<array{start: \DateTimeImmutable, end: \DateTimeImmutable}> calendar years, both included */
    private function years(int $first, int $last): array
    {
        $windows = [];
        for ($year = $first; $year <= $last; ++$year) {
            $windows[] = ['start' => new \DateTimeImmutable($year.'-01-01'), 'end' => new \DateTimeImmutable($year.'-12-31')];
        }

        return $windows;
    }

    /**
     * @param list<PlanRow> $rows
     *
     * @return array<int, int> by year
     */
    private function dotations(array $rows): array
    {
        $dotations = [];
        foreach ($rows as $row) {
            $dotations[(int) $row->start->format('Y')] = $row->dotation->cents;
        }

        return $dotations;
    }
}
