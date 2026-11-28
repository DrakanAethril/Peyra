<?php

declare(strict_types=1);

namespace App\Tests\Service\Payroll\Engine;

use App\Service\Payroll\Engine\GeneralReductionFormula;
use App\Service\Payroll\Engine\GeneralReductionRgcp;
use App\Service\Payroll\Engine\GeneralReductionRgdu;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The general reduction, one formula per millésime (lot 4, step 4.3b), on the regulatory values of
 * the repository. The expected coefficients are worked out by hand from the formulas of
 * plan/donnees/reglementaire/LISEZ-MOI.md and of service-public F24542, or are the official
 * examples themselves: the Urssaf's (2 000,00 €, 70 employees, June 2026: 0,3178, 635,60 €) and
 * service-public's (3 500,00 € a month: 0,0611 below 50, 0,0615 from 50).
 */
final class GeneralReductionTest extends TestCase
{
    use StandardPlanFixture;

    public function testTheFormulaIsTheMillesimes(): void
    {
        self::assertInstanceOf(GeneralReductionRgcp::class, GeneralReductionFormula::for(self::regulatoryOn('2025-12-31')));
        self::assertInstanceOf(GeneralReductionRgdu::class, GeneralReductionFormula::for(self::regulatoryOn('2026-01-01')));
    }

    /** @return iterable<string, array{string, string, string, ?string, ?string}> day, gross, headcount, coefficient, amount */
    public static function rgdu(): iterable
    {
        // 3 × 1 823,03 = 5 469,09 ; (5 469,09 ÷ 2 000,00 − 1) ÷ 2 = 0,867272 ; ^1,75 = 0,779421.
        // 0,02 + 0,3821 × 0,779421 = 0,317817 → 0,3178 ; × 2 000,00 = 635,60 (the Urssaf's example, 70 employees).
        yield 'Urssaf, 2 000 €, 50 and more, June' => ['2026-06-30', '2000.00', '70', '0.3178', '635.60'];
        // 0,02 + 0,3781 × 0,779421 = 0,314699 → 0,3147 ; × 2 000,00 = 629,40.
        yield '2 000 €, below 50, January' => ['2026-01-31', '2000.00', '8.69', '0.3147', '629.40'];
        // (5 469,09 ÷ 3 500,00 − 1) ÷ 2 = 0,281299 ; ^1,75 = 0,108653 ; 0,02 + 0,3781 × 0,108653 = 0,061082 → 0,0611 ; × 3 500 = 213,85.
        yield 'service-public, 3 500 €, below 50' => ['2026-03-31', '3500.00', '20', '0.0611', '213.85'];
        // 0,02 + 0,3821 × 0,108653 = 0,061516 → 0,0615 ; × 3 500 = 215,25.
        yield 'service-public, 3 500 €, 50 and more' => ['2026-03-31', '3500.00', '50', '0.0615', '215.25'];
        // At the SMIC the bracket is 1: C = 0,02 + 0,3781 = 0,3981, the maximum ; 1 823,03 × 0,3981 = 725,7482 → 725,75.
        yield 'at the SMIC, the maximum' => ['2026-02-28', '1823.03', '8.69', '0.3981', '725.75'];
        // A cent under 3 SMIC the bracket is 0,000001: C = 0,0200 ; 5 469,08 × 0,02 = 109,3816 → 109,38.
        yield 'a cent under 3 SMIC, Tmin' => ['2026-09-30', '5469.08', '8.69', '0.0200', '109.38'];
        yield 'at 3 SMIC, nothing' => ['2026-09-30', '5469.09', '8.69', null, null];
    }

    #[DataProvider('rgdu')]
    public function testTheSingleDegressiveReductionOf2026(string $day, string $gross, string $headcount, ?string $coefficient, ?string $amount): void
    {
        $formula = GeneralReductionFormula::for(self::regulatoryOn($day));
        $reduction = $formula->calculate(Money::euros($gross), $formula->smic(BigDecimal::of('151.67'), BigDecimal::of('151.67'), BigDecimal::zero()), BigDecimal::of($headcount));

        self::assertSame([$coefficient, $amount], [null === $reduction ? null : (string) $reduction->coefficient, null === $reduction ? null : (string) $reduction->amount->toDecimal()]);
        if (null !== $reduction) {
            self::assertSame('rgdu', $reduction->regime);
            self::assertSame('social.rgdu.parameters', $reduction->source->key);
        }
    }

    /** @return iterable<string, array{string, string, string, ?string, ?string}> */
    public static function rgcp(): iterable
    {
        // 1,6 × 1 801,80 = 2 882,88 ; 2 882,88 ÷ 2 000,00 − 1 = 0,44144 ; 0,3193 ÷ 0,6 × 0,44144 = 0,234920 → 0,2349 ; × 2 000 = 469,80.
        yield '2 000 €, below 50, June' => ['2025-06-30', '2000.00', '8.69', '0.2349', '469.80'];
        // 0,3233 ÷ 0,6 × 0,44144 = 0,237863 → 0,2379 ; × 2 000 = 475,80.
        yield '2 000 €, 50 and more, June' => ['2025-06-30', '2000.00', '60', '0.2379', '475.80'];
        // Before May 2025 T is 0,3194 (décret 2025-318): 0,3194 ÷ 0,6 × 0,44144 = 0,234993 → 0,2350 ; × 2 000 = 470,00.
        yield '2 000 €, below 50, January' => ['2025-01-31', '2000.00', '8.69', '0.2350', '470.00'];
        // At the SMIC: 0,3193 ÷ 0,6 × 0,6 = 0,3193, the maximum T ; 1 801,80 × 0,3193 = 575,3147 → 575,31.
        yield 'at the SMIC, T' => ['2025-10-31', '1801.80', '8.69', '0.3193', '575.31'];
        yield 'at 1,6 SMIC, nothing' => ['2025-10-31', '2882.88', '8.69', null, null];
    }

    #[DataProvider('rgcp')]
    public function testTheGeneralReductionOf2025(string $day, string $gross, string $headcount, ?string $coefficient, ?string $amount): void
    {
        $formula = GeneralReductionFormula::for(self::regulatoryOn($day));
        $reduction = $formula->calculate(Money::euros($gross), $formula->smic(BigDecimal::of('151.67'), BigDecimal::of('151.67'), BigDecimal::zero()), BigDecimal::of($headcount));

        self::assertSame([$coefficient, $amount], [null === $reduction ? null : (string) $reduction->coefficient, null === $reduction ? null : (string) $reduction->amount->toDecimal()]);
        if (null !== $reduction) {
            self::assertSame('rgcp', $reduction->regime);
        }
    }

    public function testTheSmicOfReferenceIsJanuarysProratedAndRaisedByTheOvertime(): void
    {
        // June 2026 revalued the SMIC to 1 867,02: the reduction keeps January's 1 823,03.
        $june = GeneralReductionFormula::for(self::regulatoryOn('2026-11-30'));
        self::assertSame('1823.03', (string) $june->smic(BigDecimal::of('151.67'), BigDecimal::of('151.67'), BigDecimal::zero())->toDecimal());
        // Part time 104 h: 1 823,03 × 104 ÷ 151,67 = 1 250,0502 → 1 250,05 ; + 12,02 × 10 h = 120,20 → 1 370,25.
        self::assertSame('1370.25', (string) $june->smic(BigDecimal::of('104'), BigDecimal::of('151.67'), BigDecimal::of('10'))->toDecimal());
        // 2025: 1 801,80 + 11,88 × 4 h = 47,52 → 1 849,32.
        self::assertSame('1849.32', (string) GeneralReductionFormula::for(self::regulatoryOn('2025-12-31'))->smic(BigDecimal::of('151.67'), BigDecimal::of('151.67'), BigDecimal::of('4'))->toDecimal());
    }

    public function testTheImputationGivesTheUrssafItsShareAndTheRestToTheAgircArrco(): void
    {
        $formula = GeneralReductionFormula::for(self::regulatoryOn('2026-11-30'));
        $reduction = $formula->calculate(Money::euros('2472.00'), Money::euros('1823.03'), BigDecimal::of('8.69'));

        // 438,78 × (0,3981 − 0,0601) ÷ 0,3981 = 372,5357… → 372,54 ; 438,78 − 372,54 = 66,24.
        self::assertSame(['438.78', '372.54', '66.24'], [(string) $reduction?->amount->toDecimal(), (string) $reduction?->urssafShare->toDecimal(), (string) $reduction?->retirementShare->toDecimal()]);
        self::assertStringContainsString('0,606208', implode(' ', $reduction->steps ?? []));
        self::assertStringContainsString('0,416473', implode(' ', $reduction->steps ?? []));
    }
}
