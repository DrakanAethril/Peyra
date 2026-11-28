<?php

declare(strict_types=1);

namespace App\Tests\Service\Payroll\Engine;

use App\Enum\Payroll\ContractType;
use App\Enum\Payroll\PayrollItemKind;
use App\Enum\Payroll\PayslipLine;
use App\Enum\Payroll\PayslipUnit;
use App\Service\Payroll\Engine\Payslip;
use App\Service\Payroll\Engine\PayslipCalculator;
use App\Service\Payroll\Engine\PayslipEmployee;
use App\Service\Payroll\Engine\PayslipEstablishment;
use App\Service\Payroll\Engine\PayslipInput;
use App\Service\Payroll\Engine\PayslipVariables;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

/**
 * The contributions of reference payslips (lot 4, step 4.3b), on the standard plan de paie, the
 * demonstration dossier's complementary health (28,00 € a side) and the regulatory values of the
 * repository. Every expected figure is worked out by hand in the comments from the rates of
 * plan/donnees/reglementaire/ - never copied from the engine. Each share is base × rate, rounded
 * half up to the cent on its own; a line of the clarified payslip sums its rubriques.
 *
 * Establishment by default: 8,69 people (below 11, 20 and 50), work accident 2,10 %, no mobility.
 */
final class PayslipContributionsTest extends TestCase
{
    use StandardPlanFixture;

    /** Maquette 4: Thomas Lefèvre, November 2026, gross 2 472,00, non-executive, below 50. */
    public function testThomasLefevreOfMaquette4(): void
    {
        $payslip = $this->payslip('2026-11-30', $this->employee('2400.00'), new PayslipVariables(rates: ['1010' => BigDecimal::of('3')]));

        self::assertSame('2472.00', (string) $payslip->gross->toDecimal());
        // Maladie 13 % (no reduced rate in 2026): 2 472,00 × 13 % = 321,36.
        $this->assertContribution($payslip, '2000', '2472.00', null, '0.00', '13', '321.36');
        // AT 2,10 %: 51,912 → 51,91.
        $this->assertContribution($payslip, '2100', '2472.00', null, '0.00', '2.1', '51.91');
        // Tranche 1 = min(2 472,00 ; 4 005,00): 6,90 % → 170,568 → 170,57 ; 8,55 % → 211,356 → 211,36.
        $this->assertContribution($payslip, '2200', '2472.00', '6.9', '170.57', '8.55', '211.36');
        // 0,40 % → 9,888 → 9,89 ; 2,11 % → 52,1592 → 52,16.
        $this->assertContribution($payslip, '2210', '2472.00', '0.4', '9.89', '2.11', '52.16');
        // Agirc-Arrco T1 3,15 % → 77,868 → 77,87 ; 4,72 % → 116,6784 → 116,68 ; CEG 0,86 % → 21,2592 → 21,26 ; 1,29 % → 31,8888 → 31,89.
        $this->assertContribution($payslip, '2300', '2472.00', '3.15', '77.87', '4.72', '116.68');
        $this->assertContribution($payslip, '2310', '2472.00', '0.86', '21.26', '1.29', '31.89');
        // Famille 5,25 % → 129,78 ; chômage 4 % → 98,88 ; AGS 0,25 % → 6,18.
        $this->assertContribution($payslip, '2400', '2472.00', null, '0.00', '5.25', '129.78');
        $this->assertContribution($payslip, '2500', '2472.00', null, '0.00', '4', '98.88');
        $this->assertContribution($payslip, '2510', '2472.00', null, '0.00', '0.25', '6.18');
        // CSA 0,30 % → 7,416 → 7,42 ; FNAL 0,10 % → 2,472 → 2,47 ; dialogue social 0,016 % → 0,39552 → 0,40 ;
        // formation (below 11) 0,55 % → 13,596 → 13,60 ; taxe d'apprentissage 0,68 % → 16,8096 → 16,81.
        $this->assertContribution($payslip, '2600', '2472.00', null, '0.00', '0.3', '7.42');
        $this->assertContribution($payslip, '2610', '2472.00', null, '0.00', '0.1', '2.47');
        $this->assertContribution($payslip, '2630', '2472.00', null, '0.00', '0.016', '0.40');
        $this->assertContribution($payslip, '2640', '2472.00', null, '0.00', '0.55', '13.60');
        $this->assertContribution($payslip, '2660', '2472.00', null, '0.00', '0.68', '16.81');
        // CSG base: 2 472,00 × 98,25 % = 2 428,74, + the employer's health 28,00 = 2 456,74 (maquette 4).
        // 6,80 % → 167,05832 → 167,06 ; 2,40 % → 58,96176 → 58,96 ; CRDS 0,50 % → 12,2837 → 12,28.
        $this->assertContribution($payslip, '2800', '2456.74', '6.8', '167.06', null, null);
        $this->assertContribution($payslip, '2810', '2456.74', '2.4', '58.96', null, null);
        $this->assertContribution($payslip, '2820', '2456.74', '0.5', '12.28', null, null);
        // RGDU: (3 × 1 823,03 ÷ 2 472,00 − 1) ÷ 2 = 0,606208 ; ^1,75 = 0,416473 ; 0,02 + 0,3781 × 0,416473 = 0,177469
        // → 0,1775 ; 2 472,00 × 0,1775 = 438,78, the maquette's « − 438,78 ».
        $this->assertContribution($payslip, '2900', '2472.00', null, '0.00', '0.1775', '-438.78');
        self::assertSame(PayslipUnit::Coefficient, $payslip->row('2900')?->employerRateUnit);
        // No tranche 2, no CET (2 472,00 ≤ 4 005,00), no executive's rubrique, no overtime, no rubrique from 11 people.
        self::assertSame(['2000', '2010', '2100', '2200', '2210', '2300', '2310', '2400', '2500', '2510', '2600', '2610', '2630', '2640', '2660', '2800', '2810', '2820', '2900'], $this->contributionCodes($payslip));

        // Rubrique by rubrique, the clarified payslip's lines: « Autres contributions » 7,42 + 2,47 + 0,40 + 13,60 + 16,81 =
        // 40,70 where maquette 4 prints 2 472,00 × 1,646 % = 40,69 (one rate, one rounding: A-VERIFIER) ; « CSG et CRDS non
        // déductibles » 58,96 + 12,28 = 71,24 where it prints 2 456,74 × 2,90 % = 71,25.
        self::assertSame('40.70', (string) $payslip->lineTotal(PayslipLine::OtherEmployerContributions)['employer']->toDecimal());
        self::assertSame('71.24', (string) $payslip->lineTotal(PayslipLine::CsgCrdsNonDeductible)['employee']->toDecimal());
        self::assertSame('99.13', (string) $payslip->lineTotal(PayslipLine::ComplementaryTranche1)['employee']->toDecimal());
        self::assertSame('148.57', (string) $payslip->lineTotal(PayslipLine::ComplementaryTranche1)['employer']->toDecimal());
        self::assertSame('105.06', (string) $payslip->lineTotal(PayslipLine::Unemployment)['employer']->toDecimal());
        // Totals: employee 170,57 + 9,89 + 77,87 + 21,26 + 28,00 + 167,06 + 58,96 + 12,28 = 545,89 (maquette 545,90) ;
        // employer 321,36 + 28,00 + 51,91 + 211,36 + 52,16 + 116,68 + 31,89 + 129,78 + 98,88 + 6,18 + 40,70 − 438,78 = 650,12
        // (maquette 650,11).
        self::assertSame(['545.89', '650.12'], $this->totals($payslip));

        // 438,78 × (0,3981 − 0,0601) ÷ 0,3981 = 372,54 on the Urssaf, 66,24 on the Agirc-Arrco.
        self::assertSame(['0.1775', '372.54', '66.24'], [(string) $payslip->generalReduction?->coefficient, (string) $payslip->generalReduction?->urssafShare->toDecimal(), (string) $payslip->generalReduction?->retirementShare->toDecimal()]);
    }

    /** Paul Vasseur, January 2026: executive, 4 650,00 and a company car 322,50 - gross 4 972,50, above the ceiling. */
    public function testAnExecutiveAboveTheCeiling2026(): void
    {
        $payslip = $this->payslip('2026-01-31', $this->employee('4650.00', executive: true), new PayslipVariables(amounts: ['1400' => Money::euros('322.50')]));

        // T1 = 4 005,00 ; T2 = 4 972,50 − 4 005,00 = 967,50 ; T1 + T2 and 4 PSS = 4 972,50.
        // Prévoyance des cadres 1,50 % of T1 → 60,075 → 60,08.
        $this->assertContribution($payslip, '2050', '4005', null, '0.00', '1.5', '60.08');
        // 6,90 % of 4 005,00 → 276,345 → 276,35 ; 8,55 % → 342,4275 → 342,43.
        $this->assertContribution($payslip, '2200', '4005', '6.9', '276.35', '8.55', '342.43');
        // Agirc-Arrco T2 8,64 % of 967,50 → 83,592 → 83,59 ; 12,95 % → 125,29125 → 125,29 ; CEG T2 1,08 % → 10,449 → 10,45 ; 1,62 % → 15,6735 → 15,67.
        $this->assertContribution($payslip, '2320', '967.50', '8.64', '83.59', '12.95', '125.29');
        $this->assertContribution($payslip, '2330', '967.50', '1.08', '10.45', '1.62', '15.67');
        // CET, due above the ceiling, on T1 + T2: 0,14 % of 4 972,50 → 6,9615 → 6,96 ; 0,21 % → 10,44225 → 10,44.
        $this->assertContribution($payslip, '2340', '4972.50', '0.14', '6.96', '0.21', '10.44');
        // Apec within 4 PSS: 0,024 % → 1,1934 → 1,19 ; 0,036 % → 1,7901 → 1,79.
        $this->assertContribution($payslip, '2520', '4972.50', '0.024', '1.19', '0.036', '1.79');
        // FNAL below 50, on T1: 0,10 % of 4 005,00 = 4,005 → 4,01.
        $this->assertContribution($payslip, '2610', '4005', null, '0.00', '0.1', '4.01');
        // CSG base: 4 972,50 × 98,25 % = 4 885,48125 → 4 885,48 ; + health 28,00 + prévoyance 60,08 = 4 973,56.
        // 6,80 % → 338,20208 → 338,20 ; 2,40 % → 119,36544 → 119,37 ; 0,50 % → 24,8678 → 24,87.
        $this->assertContribution($payslip, '2800', '4973.56', '6.8', '338.20', null, null);
        $this->assertContribution($payslip, '2820', '4973.56', '0.5', '24.87', null, null);
        // RGDU: (5 469,09 ÷ 4 972,50 − 1) ÷ 2 = 0,049934 ; ^1,75 = 0,005275 ; 0,02 + 0,3781 × 0,005275 = 0,021994 → 0,0220 ;
        // 4 972,50 × 0,0220 = 109,395 → 109,40 ; Urssaf 109,40 × 0,3380 ÷ 0,3981 = 92,88, Agirc-Arrco 16,52.
        $this->assertContribution($payslip, '2900', '4972.50', null, '0.00', '0.022', '-109.40');
        self::assertSame(['92.88', '16.52'], [(string) $payslip->generalReduction?->urssafShare->toDecimal(), (string) $payslip->generalReduction?->retirementShare->toDecimal()]);
        // Employee: 276,35 + 19,89 + 126,16 + 34,44 + 83,59 + 10,45 + 6,96 + 1,19 + 28,00 + 338,20 + 119,37 + 24,87 = 1 069,47.
        self::assertSame('1069.47', $this->totals($payslip)[0]);
    }

    /** Claire Bonnet, June 2025: 2 350,00 + bonus 10 %, gross 2 585,00 - the reduced rates and the general reduction of 2025. */
    public function testTheReducedRatesAndTheGeneralReductionOf2025(): void
    {
        $payslip = $this->payslip('2025-06-30', $this->employee('2350.00'), new PayslipVariables(rates: ['1010' => BigDecimal::of('10')]), demo: false);

        self::assertSame('2585.00', (string) $payslip->gross->toDecimal());
        // 2 585,00 ≤ 2,25 × 1 801,80 = 4 054,05: maladie at 7 % → 180,95 ; ≤ 3,3 × 1 801,80 = 5 945,94: famille at 3,45 % → 89,1825 → 89,18.
        $this->assertContribution($payslip, '2000', '2585.00', null, '0.00', '7', '180.95');
        $this->assertContribution($payslip, '2400', '2585.00', null, '0.00', '3.45', '89.18');
        // Vieillesse déplafonnée 2025: 2,02 % → 52,217 → 52,22 ; chômage from May 2025 4 % → 103,40.
        $this->assertContribution($payslip, '2210', '2585.00', '0.4', '10.34', '2.02', '52.22');
        $this->assertContribution($payslip, '2500', '2585.00', null, '0.00', '4', '103.40');
        // CSG base, no health insurance in the standard plan: 2 585,00 × 98,25 % = 2 539,7625 → 2 539,76 ; 6,80 % → 172,70.
        $this->assertContribution($payslip, '2800', '2539.76', '6.8', '172.70', null, null);
        // RGCP: 0,3193 ÷ 0,6 × (2 882,88 ÷ 2 585,00 − 1) = 0,532167 × 0,115234 = 0,061324 → 0,0613 ; 2 585,00 × 0,0613 = 158,4605
        // → 158,46 ; Urssaf 158,46 × (0,3193 − 0,0601) ÷ 0,3193 = 128,63, Agirc-Arrco 29,83.
        $this->assertContribution($payslip, '2900', '2585.00', null, '0.00', '0.0613', '-158.46');
        self::assertSame(['rgcp', '128.63', '29.83'], [$payslip->generalReduction?->regime, (string) $payslip->generalReduction?->urssafShare->toDecimal(), (string) $payslip->generalReduction?->retirementShare->toDecimal()]);
        $note = implode(' ', $payslip->row('2000')->explanation->notes ?? []);
        self::assertStringContainsString('taux réduit', $note);
    }

    public function testTheReducedRateStopsAbove225Smic2025(): void
    {
        // 2,25 × 1 801,80 = 4 054,05: reduced at 4 054,05, full a cent above.
        $at = $this->payslip('2025-09-30', $this->employee('4054.05'), PayslipVariables::none(), demo: false);
        $above = $this->payslip('2025-09-30', $this->employee('4054.06'), PayslipVariables::none(), demo: false);

        self::assertSame(['7', '13'], [(string) $at->row('2000')?->employerRate, (string) $above->row('2000')?->employerRate]);
        // 4 054,06 ≤ 5 945,94: allocations familiales still reduced.
        self::assertSame('3.45', (string) $above->row('2400')?->employerRate);
        // Above 1,6 SMIC (2 882,88): no general reduction.
        self::assertNull($above->row('2900'));
        self::assertNull($above->generalReduction);
    }

    /** Mehdi Benali, June 2026: gross 2 513,13 with 12 overtime hours at 25 % (212,63). */
    public function testOvertimeReductionsAndTheSmicOfReference(): void
    {
        $payslip = $this->payslip('2026-06-30', $this->employee('2150.00'), new PayslipVariables(
            quantities: ['1100' => BigDecimal::of('12')],
            rates: ['1010' => BigDecimal::of('7')],
        ));

        self::assertSame('2513.13', (string) $payslip->gross->toDecimal());
        // Employee reduction: 6,90 + 0,40 + 3,15 + 0,86 = 11,31 % (all under the ceiling) ; 212,63 × 11,31 % = 24,048453 → 24,05.
        $this->assertContribution($payslip, '2910', '212.63', '11.31', '-24.05', null, null);
        // Employer's flat deduction below 20: 12 h × 1,50 € = 18,00.
        $this->assertContribution($payslip, '2920', '12', null, '0.00', '1.5', '-18.00');
        self::assertSame(PayslipUnit::EurosPerHour, $payslip->row('2920')?->employerRateUnit);
        self::assertNull($payslip->row('2930'));
        // SMIC of reference 1 823,03 + 12,02 × 12 h = 1 967,27 ; (3 × 1 967,27 ÷ 2 513,13 − 1) ÷ 2 = 0,674195 ; ^1,75 = 0,501620 ;
        // 0,02 + 0,3781 × 0,501620 = 0,209663 → 0,2097 ; 2 513,13 × 0,2097 = 527,0034 → 527,00.
        self::assertSame(['1967.27', '0.2097', '527.00'], [(string) $payslip->generalReduction?->smic->toDecimal(), (string) $payslip->generalReduction?->coefficient, (string) $payslip->generalReduction?->amount->toDecimal()]);
    }

    /** Sandrine Gauthier, December 2026: part time 104 h, gross 1 751,14 with 10 complementary hours (156,20). */
    public function testComplementaryHoursOfAPartTime(): void
    {
        $payslip = $this->payslip('2026-12-31', $this->employee('1476.80', '104'), new PayslipVariables(
            quantities: ['1120' => BigDecimal::of('10')],
            rates: ['1010' => BigDecimal::of('8')],
        ));

        self::assertSame('1751.14', (string) $payslip->gross->toDecimal());
        // 156,20 × 11,31 % = 17,66622 → 17,67 ; complementary hours open no employer's deduction.
        $this->assertContribution($payslip, '2910', '156.20', '11.31', '-17.67', null, null);
        self::assertNull($payslip->row('2920'));
        // SMIC: 1 823,03 × 104 ÷ 151,67 = 1 250,05 ; + 12,02 × 10 h = 1 370,25 ; (3 × 1 370,25 ÷ 1 751,14 − 1) ÷ 2 = 0,673735 ;
        // ^1,75 = 0,501022 ; 0,02 + 0,3781 × 0,501022 = 0,209436 → 0,2094 ; 1 751,14 × 0,2094 = 366,6887 → 366,69.
        self::assertSame(['1370.25', '0.2094', '366.69'], [(string) $payslip->generalReduction?->smic->toDecimal(), (string) $payslip->generalReduction?->coefficient, (string) $payslip->generalReduction?->amount->toDecimal()]);
    }

    /** Paul Vasseur with 4 overtime hours: above the ceiling, the employee reduction takes the effective old-age rate. */
    public function testTheOvertimeReductionAboveTheCeiling(): void
    {
        $payslip = $this->payslip('2026-01-31', $this->employee('4650.00', executive: true), new PayslipVariables(
            quantities: ['1100' => BigDecimal::of('4')],
            amounts: ['1400' => Money::euros('322.50')],
        ));

        // 4 650,00 ÷ 151,67 = 30,6587 ; × 1,25 = 38,3234 ; × 4 h = 153,29 ; gross 4 650,00 + 153,29 + 322,50 = 5 125,79.
        self::assertSame('5125.79', (string) $payslip->gross->toDecimal());
        // (6,90 × 4 005 + 0,40 × 5 125,79 + 3,15 × 4 005 + 0,86 × 4 005 + 8,64 × 1 120,79 + 1,08 × 1 120,79 + 0,14 × 5 125,79)
        // ÷ 5 125,79 = 11,1898 % < 11,31 % ; 153,29 × 11,1898 % = 17,1528 → 17,15.
        $this->assertContribution($payslip, '2910', '153.29', '11.1898', '-17.15', null, null);
        // 4 h × 1,50 = 6,00.
        $this->assertContribution($payslip, '2920', '4', null, '0.00', '1.5', '-6.00');
    }

    /** From 11 people: vocational training at 1 %, the mobility payment, the forfait social on the health insurance. */
    public function testRubriquesFrom11People(): void
    {
        $payslip = $this->payslip('2026-11-30', $this->employee('2400.00'), new PayslipVariables(rates: ['1010' => BigDecimal::of('3')]), establishment: self::establishment('12', '1.8'));

        // 2 472,00 × 1 % = 24,72 ; mobility 1,80 % → 44,496 → 44,50 ; forfait social 8 % of the employer's health 28,00 = 2,24.
        $this->assertContribution($payslip, '2650', '2472.00', null, '0.00', '1', '24.72');
        $this->assertContribution($payslip, '2670', '2472.00', null, '0.00', '1.8', '44.50');
        $this->assertContribution($payslip, '2700', '28.00', null, '0.00', '8', '2.24');
        self::assertNull($payslip->row('2640'));
        self::assertNotNull($payslip->row('2610'));
    }

    /** From 50 people: the FNAL at 0,50 % on the whole gross, the RGDU's Tdelta 0,3821, the flat deduction of 0,50 €. */
    public function testRubriquesFrom50People(): void
    {
        $payslip = $this->payslip('2026-06-30', $this->employee('2000.00'), new PayslipVariables(quantities: ['1100' => BigDecimal::of('2')]), establishment: self::establishment('70'), demo: false);

        // 2 000,00 ÷ 151,67 = 13,1865 ; × 1,25 = 16,483125 → 16,4831 ; × 2 h = 32,9662 → 32,97 ; gross 2 032,97.
        self::assertSame('2032.97', (string) $payslip->gross->toDecimal());
        // FNAL 0,50 % → 10,16485 → 10,16.
        $this->assertContribution($payslip, '2620', '2032.97', null, '0.00', '0.5', '10.16');
        self::assertNull($payslip->row('2610'));
        // 2 h × 0,50 = 1,00.
        $this->assertContribution($payslip, '2930', '2', null, '0.00', '0.5', '-1.00');
        // SMIC 1 823,03 + 12,02 × 2 = 1 847,07 ; (3 × 1 847,07 ÷ 2 032,97 − 1) ÷ 2 = 0,862836 ; ^1,75 = 0,772457 ;
        // 0,02 + 0,3821 × 0,772457 = 0,315156 → 0,3152 ; 2 032,97 × 0,3152 = 640,7921 → 640,79.
        self::assertSame(['1847.07', '0.3152', '640.79'], [(string) $payslip->generalReduction?->smic->toDecimal(), (string) $payslip->generalReduction?->coefficient, (string) $payslip->generalReduction?->amount->toDecimal()]);
    }

    /** Every contribution explains its calculation: each share with its figures, its base's origin, its rate's. */
    public function testEveryContributionExplainsItself(): void
    {
        $payslip = $this->payslip('2026-11-30', $this->employee('2400.00'), new PayslipVariables(rates: ['1010' => BigDecimal::of('3')]));

        $old = $payslip->row('2200')?->explanation;
        self::assertSame("Part salarié : 2\u{202f}472,00\u{a0}€ × 6,90\u{a0}% = 170,57\u{a0}€ ; Part employeur : 2\u{202f}472,00\u{a0}€ × 8,55\u{a0}% = 211,36\u{a0}€.", $old?->formula);
        self::assertStringContainsString('Tranche 1', $old->base);
        self::assertStringContainsString("4\u{202f}005,00", $old->base);
        self::assertSame('social.contrib.old_age_capped', $old->source?->key);
        $csg = $payslip->row('2800')?->explanation;
        self::assertStringContainsString('98,25', (string) $csg?->base);
        self::assertStringContainsString('28,00', (string) $csg?->base);
        $reduction = $payslip->row('2900')?->explanation;
        self::assertStringContainsString("1\u{202f}823,03", (string) $reduction?->base);
        self::assertStringContainsString('0,1775', (string) $reduction?->rate);
        self::assertStringContainsString('372,54', implode(' ', $reduction->notes ?? []));
        self::assertSame('social.rgdu.parameters', $reduction?->source?->key);
        foreach ($payslip->rows as $row) {
            self::assertNotSame('', $row->explanation->formula, $row->code);
            self::assertNotSame('', $row->explanation->base, $row->code);
            self::assertNotSame('', $row->explanation->rate, $row->code);
        }
    }

    private function employee(string $salary, string $hours = '151.67', bool $executive = false): PayslipEmployee
    {
        return new PayslipEmployee('Salarié', $executive, ContractType::Permanent, $hours, Money::euros($salary));
    }

    private function payslip(string $day, PayslipEmployee $employee, PayslipVariables $variables, ?PayslipEstablishment $establishment = null, bool $demo = true): Payslip
    {
        return PayslipCalculator::calculate(new PayslipInput($employee, $variables, self::regulatoryOn($day), $demo ? self::demoPlan() : self::standardPlan(), $establishment ?? self::establishment()));
    }

    /** @return list<string> */
    private function contributionCodes(Payslip $payslip): array
    {
        return array_values(array_map(static fn ($row): string => $row->code, array_filter($payslip->rows, static fn ($row): bool => PayrollItemKind::Contribution === $row->kind)));
    }

    /** @return array{string, string} the employee's and the employer's contributions, summed */
    private function totals(Payslip $payslip): array
    {
        $employee = Money::zero();
        $employer = Money::zero();
        foreach ($payslip->rows as $row) {
            if (PayrollItemKind::Contribution === $row->kind) {
                $employee = $employee->plus($row->employeeAmount);
                $employer = $employer->plus($row->employerAmount ?? Money::zero());
            }
        }

        return [(string) $employee->toDecimal(), (string) $employer->toDecimal()];
    }

    private function assertContribution(Payslip $payslip, string $code, ?string $base, ?string $employeeRate, string $employeeAmount, ?string $employerRate, ?string $employerAmount): void
    {
        $row = $payslip->row($code);
        self::assertNotNull($row, 'No line '.$code);
        self::assertSame([$base, $employeeRate, $employeeAmount, $employerRate, $employerAmount], [
            null === $row->base ? null : (string) $row->base,
            null === $row->employeeRate ? null : (string) $row->employeeRate->strippedOfTrailingZeros(),
            (string) $row->employeeAmount->toDecimal(),
            null === $row->employerRate ? null : (string) $row->employerRate->strippedOfTrailingZeros(),
            null === $row->employerAmount ? null : (string) $row->employerAmount->toDecimal(),
        ], 'Line '.$code);
    }
}
