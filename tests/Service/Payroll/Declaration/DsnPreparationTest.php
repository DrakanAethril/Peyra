<?php

declare(strict_types=1);

namespace App\Tests\Service\Payroll\Declaration;

use App\Enum\Payroll\ContractType;
use App\Enum\Payroll\PayrollItemKind;
use App\Enum\Payroll\PayslipLine;
use App\Enum\Payroll\Sex;
use App\Enum\Payroll\WorkingTime;
use App\Service\Payroll\Declaration\DsnControl;
use App\Service\Payroll\Declaration\DsnIndividual;
use App\Service\Payroll\Declaration\DsnPreparation;
use App\Service\Payroll\Engine\Payslip;
use App\Service\Payroll\Engine\PayslipExplanation;
use App\Service\Payroll\Engine\PayslipRow;
use App\Service\Payroll\Engine\PayslipTotals;
use App\Service\Payroll\Engine\PayslipYearToDate;
use App\Service\Payroll\Statement\PayrollChart;
use App\Service\Payroll\Statement\PayrollMonthPayslip;
use App\Service\Payroll\Statement\PayrollMonthStatements;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

/**
 * The DSN's preparatory state (step 4.6), pure, on two payslips written by hand:
 *
 * S001 Martin: old age capped 100,00 / 150,00, complementary T1 50,00 / 60,00 → 360,00.
 * S002 Bernard: old age capped 80,00 / 120,00 → 200,00.
 * Declared, body by body: 360 + 200 = 560,00, the état des charges' total.
 */
final class DsnPreparationTest extends TestCase
{
    public function testEverythingHolds(): void
    {
        $preparation = DsnPreparation::of($this->statements(), [$this->individual('S001', 'Martin', '1 85 03 75 112 001 00'), $this->individual('S002', 'Bernard', '2 90 11 69 123 045 00')], ['S001' => 'Martin Paul', 'S002' => 'Bernard Paul'], []);

        self::assertSame(56000, $preparation->contributions()->cents, '100 + 150 + 50 + 60 + 80 + 120.');
        self::assertTrue($preparation->holds());
        self::assertSame([true, true, true], array_map(static fn (DsnControl $c): bool => $c->holds, $preparation->controls));
        self::assertSame("560,00\u{a0}€ déclarés par organisme, 560,00\u{a0}€ à l’état des charges.", $preparation->controls[0]->finding);
        self::assertSame('2 salariés, 2 bulletins validés.', $preparation->controls[1]->finding);
    }

    public function testAMissingPayslipAnExtraOneAndAMissingNir(): void
    {
        $preparation = DsnPreparation::of($this->statements(), [$this->individual('S001', 'Martin', null), $this->individual('S002', 'Bernard', '2 90 11 69 123 045 00')], ['S001' => 'Martin Paul', 'S003' => 'Durand Léa'], []);

        self::assertFalse($preparation->holds());
        self::assertTrue($preparation->controls[0]->holds);
        self::assertFalse($preparation->controls[1]->holds);
        self::assertSame('Sans bulletin validé : S003 Durand Léa. Bulletin validé hors des salariés du mois : S002 Bernard Paul.', $preparation->controls[1]->finding);
        self::assertSame('Manquant : S001 Martin Paul.', $preparation->controls[2]->finding);
    }

    private function statements(): PayrollMonthStatements
    {
        $martin = new Payslip([
            self::row('2200', PayslipLine::SocialSecurityCapped, '100.00', '150.00'),
            self::row('2300', PayslipLine::ComplementaryTranche1, '50.00', '60.00'),
        ], Money::euros('2000'), null, self::totals(), PayslipYearToDate::none());
        $bernard = new Payslip([self::row('2200', PayslipLine::SocialSecurityCapped, '80.00', '120.00')], Money::euros('1600'), null, self::totals(), PayslipYearToDate::none());

        return PayrollMonthStatements::of(new \DateTimeImmutable('2026-03-01'), [new PayrollMonthPayslip('S001', 'Martin Paul', $martin), new PayrollMonthPayslip('S002', 'Bernard Paul', $bernard)], PayrollChart::of([], []));
    }

    private function individual(string $number, string $lastName, ?string $nir): DsnIndividual
    {
        return new DsnIndividual($number, $lastName, 'Paul', Sex::Male, new \DateTimeImmutable('1985-03-02'), null, $nir, ContractType::Permanent, new \DateTimeImmutable('2020-01-06'), null, 'Menuisier', false, WorkingTime::FullTime, '151.67', null, null,
            new Payslip([], Money::zero(), null, self::totals(), PayslipYearToDate::none()));
    }

    private static function row(string $code, PayslipLine $line, string $employee, string $employer): PayslipRow
    {
        return new PayslipRow($code, 'Cotisation '.$code, PayrollItemKind::Contribution, BigDecimal::of('2000.00'), null, null, null, Money::euros($employee), new PayslipExplanation('', '', ''), null, null, Money::euros($employer), $line);
    }

    private static function totals(): PayslipTotals
    {
        return new PayslipTotals(Money::zero(), Money::zero(), Money::zero(), Money::zero(), Money::zero(), Money::zero(), BigDecimal::zero(), true, Money::zero(), Money::zero(), Money::zero(), []);
    }
}
