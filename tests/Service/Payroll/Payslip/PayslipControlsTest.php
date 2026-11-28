<?php

declare(strict_types=1);

namespace App\Tests\Service\Payroll\Payslip;

use App\Enum\Payroll\ContractType;
use App\Service\Payroll\Engine\PayslipCalculator;
use App\Service\Payroll\Engine\PayslipEmployee;
use App\Service\Payroll\Engine\PayslipInput;
use App\Service\Payroll\Engine\PayslipVariables;
use App\Service\Payroll\Engine\PayslipYearToDate;
use App\Service\Payroll\Payslip\PayslipControls;
use App\Service\Shared\Money;
use App\Tests\Service\Payroll\Engine\StandardPlanFixture;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

/**
 * The « Contrôles » card of a calculated payslip (review 4.7; maquette 4): amounts worked out by hand.
 */
final class PayslipControlsTest extends TestCase
{
    use StandardPlanFixture;

    public function testThomasLefevreOfMaquetteFour(): void
    {
        // November 2026: 2 400,00 for 151,67 h = 15,8238 € an hour, the SMIC is 12,31 € since June; brut
        // 2 472,00 under the ceiling of 4 005,00; general reduction 438,78 at 0,1775 (A-VERIFIER 4.3a).
        $employee = new PayslipEmployee('Thomas Lefèvre', false, ContractType::Permanent, '151.67', Money::euros('2400.00'), BigDecimal::of('3.5'));
        $payslip = PayslipCalculator::calculate(new PayslipInput($employee, new PayslipVariables(rates: ['1010' => BigDecimal::of('3')]), self::regulatoryOn('2026-11-30'), self::demoPlan(), self::establishment(), PayslipYearToDate::none()));
        $controls = PayslipControls::of($employee->hourlyRate(), $employee->baseSalary, BigDecimal::of('12.31'), null, $payslip->gross, BigDecimal::of('4005'), $payslip->generalReduction, 0);

        self::assertSame([
            ['tone' => 'passed', 'text' => 'Salaire horaire de 15,8238 € au moins égal au SMIC (12,31 €)'],
            ['tone' => 'passed', 'text' => "Brut sous le plafond du mois (4\u{202F}005,00\u{A0}€) : tout en tranche 1"],
            ['tone' => 'check', 'text' => "Réduction générale calculée par l’outil : 438,78\u{A0}€ (coefficient 0,1775), détail en F5 sur sa ligne"],
        ], $controls->items);
        self::assertSame(0, $controls->faults());
        self::assertNull($controls->refusal());
    }

    public function testAboveTheCeilingUnderTheSmicAndForcedValues(): void
    {
        // 4 650,00 against 4 005,00: 645,00 in tranche 2; 11,50 € an hour under 12,02 €; two cells forced.
        $controls = PayslipControls::of(BigDecimal::of('11.5'), Money::euros('4650.00'), BigDecimal::of('12.02'), null, Money::euros('4650.00'), BigDecimal::of('4005'), null, 2);

        self::assertSame(['fault', 'passed', 'passed', 'fault'], array_column($controls->items, 'tone'));
        self::assertSame("Brut au-dessus du plafond du mois (4\u{202F}005,00\u{A0}€) : 645,00\u{A0}€ en tranche 2", $controls->items[1]['text']);
        self::assertSame('2 valeurs saisies dans le bulletin diffèrent du calcul de l’outil', $controls->items[3]['text']);
        self::assertSame('2 contrôles du bulletin sont en défaut : revoyez-les avant de valider.', $controls->refusal());
    }

    public function testTheMinimumOfAnApprentice(): void
    {
        // Hugo Marchand, born 12/03/2008, apprentice from 01/09/2025: first year, 17 until his birthday,
        // 18 from the first day of the month after it (April 2026); second year from 01/09/2026.
        $minimum = static fn (string $day): int => PayslipControls::apprenticeMinimum(self::regulatoryOn($day), new \DateTimeImmutable('2008-03-12'), new \DateTimeImmutable('2025-09-01'), new \DateTimeImmutable($day))->cents;

        self::assertSame(48_649, $minimum('2025-12-31'), '27 % × 1 801,80 = 486,486.');
        self::assertSame(49_222, $minimum('2026-03-31'), '27 % × 1 823,03 = 492,2181: still 17 on the eve of March.');
        self::assertSame(78_390, $minimum('2026-04-30'), '43 % × 1 823,03 = 783,9029.');
        self::assertSame(80_282, $minimum('2026-06-30'), '43 % × 1 867,02 = 802,8186: the SMIC of June.');
        self::assertSame(95_218, $minimum('2026-09-30'), '51 % × 1 867,02 = 952,1802: second year.');

        $controls = PayslipControls::of(BigDecimal::of('3.2456'), Money::euros('492.22'), BigDecimal::of('12.02'), Money::cents(49_222), Money::euros('492.22'), BigDecimal::of('4005'), null, 0);
        self::assertSame(['tone' => 'passed', 'text' => "Salaire d’apprenti au moins égal au minimum légal (492,22\u{A0}€)"], $controls->items[0]);
        self::assertSame('Aucune réduction générale de cotisations ce mois-ci', $controls->items[2]['text']);
    }
}
