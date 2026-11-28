<?php

declare(strict_types=1);

namespace App\Tests\Service\Payroll\Payslip;

use App\Entity\Dossier\Dossier;
use App\Entity\Payroll\Employee;
use App\Entity\Payroll\EmploymentTerms;
use App\Enum\Payroll\ContractType;
use App\Enum\Payroll\ExitReason;
use App\Enum\Payroll\Sex;
use App\Enum\Payroll\WorkingTime;
use App\Service\Payroll\Engine\PaidLeave;
use App\Service\Payroll\Engine\PayslipCalculator;
use App\Service\Payroll\Engine\PayslipEmployee;
use App\Service\Payroll\Engine\PayslipInput;
use App\Service\Payroll\Engine\PayslipVariables;
use App\Service\Payroll\Engine\PayslipYearToDate;
use App\Service\Payroll\Exit\FinalSettlement;
use App\Service\Payroll\Payslip\EmployeeAbsences;
use App\Service\Payroll\Payslip\LastPayslipEntries;
use App\Service\Payroll\Payslip\PayslipElementField;
use App\Service\Payroll\Payslip\PayslipElementFields;
use App\Service\Payroll\Payslip\PayslipElements;
use App\Service\Payroll\Payslip\PayslipElementsRules;
use App\Service\Payroll\Payslip\ValidatedPayslipLocks;
use App\Service\Shared\Money;
use App\Tests\Service\Payroll\Engine\StandardPlanFixture;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

/**
 * Step 4.4c around the payslip: what the card asks more on the last payslip of a contract, the reçu pour
 * solde de tout compte read on it, what a validated payslip keeps from moving on the fiche, and the
 * absences month by month. Figures worked out in the comments.
 */
final class LastPayslipTest extends TestCase
{
    use StandardPlanFixture;

    public function testTheCardAsksMoreOnTheLastMonthOnly(): void
    {
        $plan = self::standardPlan();
        $september = new \DateTimeImmutable('2026-09-01');
        $exit = new \DateTimeImmutable('2026-09-30');

        self::assertEquals(new LastPayslipEntries(compensatoryLeave: true, fixedTermGross: true), LastPayslipEntries::of(ExitReason::FixedTermEnd, $exit, $september, $plan));
        self::assertEquals(new LastPayslipEntries(compensatoryLeave: true, severance: true), LastPayslipEntries::of(ExitReason::PersonalDismissal, $exit, $september, $plan));
        self::assertEquals(new LastPayslipEntries(compensatoryLeave: true, severance: true, agreed: true), LastPayslipEntries::of(ExitReason::ConventionalTermination, $exit, $september, $plan));
        self::assertEquals(new LastPayslipEntries(compensatoryLeave: true), LastPayslipEntries::of(ExitReason::Resignation, $exit, $september, $plan));
        // The month before the last, or no exit: nothing more.
        self::assertFalse(LastPayslipEntries::of(ExitReason::FixedTermEnd, $exit, new \DateTimeImmutable('2026-08-01'), $plan)->any());
        self::assertFalse(LastPayslipEntries::of(null, null, $september, $plan)->any());

        $codes = static fn (array $fields): array => array_map(static fn (PayslipElementField $f): string => $f->key(), array_values(array_filter($fields, static fn (PayslipElementField $f): bool => '1500' === $f->code)));
        self::assertSame(['amount.1500'], $codes(PayslipElementFields::of($plan, LastPayslipEntries::of(ExitReason::FixedTermEnd, $exit, $september, $plan))));
        self::assertSame([], $codes(PayslipElementFields::of($plan)));
    }

    public function testTheLastPayslipsEntriesAreRead(): void
    {
        $last = new LastPayslipEntries(compensatoryLeave: true, severance: true, agreed: true);
        $typed = ['compensatoryLeave.hours' => '87,5', 'compensatoryLeave.daysTaken' => '15', 'compensatoryLeave.daysAcquired' => '15', 'compensatoryLeave.referenceGross' => '9 750,00',
            'severance.referenceSalary' => '3000', 'severance.agreed' => '7000', 'severance.previousYearGross' => ''];
        $read = PayslipElementsRules::read([], false, $typed, $last);
        $elements = $read['elements'] ?? throw new \LogicException(implode(' ', $read['errors']));

        self::assertSame(['87.5', '15', '975000'], [(string) $elements->compensatoryLeave?->hours, (string) $elements->compensatoryLeave?->daysTaken, (string) $elements->compensatoryLeave?->referenceGross->cents]);
        self::assertSame([300000, 700000, null], [$elements->severance?->referenceSalary->cents, $elements->severance?->agreed?->cents, $elements->severance?->previousYearGross]);
        // Kept as JSON and read back the same.
        self::assertEquals($elements, PayslipElements::fromArray(json_decode((string) json_encode($elements->toArray()), true, flags: \JSON_THROW_ON_ERROR)));
        // A month that is not the contract's last hands none of it to the engine.
        self::assertNull($elements->toVariables()->compensatoryLeave);
        self::assertNull($elements->toVariables()->severance);
        self::assertNotNull($elements->toVariables($last)->severance);

        $refused = PayslipElementsRules::read([], false, ['compensatoryLeave.hours' => '87,5', 'severance.referenceSalary' => ''], new LastPayslipEntries(compensatoryLeave: true, severance: true));
        self::assertSame(['compensatoryLeave', 'severance.referenceSalary'], array_keys($refused['errors']));
    }

    /** Yanis Chabert, fixed-term contract ended on 30 September 2026 (the engine's case of step 4.3e). */
    public function testTheReceiptReadsTheLastPayslip(): void
    {
        $employee = new PayslipEmployee('Yanis Chabert', false, ContractType::FixedTerm, '151.67', Money::euros('1950.00'), BigDecimal::of('2.1'), new \DateTimeImmutable('2026-04-01'), new \DateTimeImmutable('2026-09-30'), ExitReason::FixedTermEnd);
        $variables = new PayslipVariables(amounts: ['1500' => Money::euros('9750.00')], compensatoryLeave: new PaidLeave('87.5', '15', '15', Money::euros('9750.00')));
        $payslip = PayslipCalculator::calculate(new PayslipInput($employee, $variables, self::regulatoryOn('2026-09-30'), self::demoPlan(), self::establishment(), PayslipYearToDate::none()));
        $settlement = FinalSettlement::of($payslip, self::demoPlan());

        // Salary 1 950,00; end of contract 10 % × 11 700,00 = 1 170,00; leave 10 % × 12 870,00 = 1 287,00.
        self::assertSame('1950.00', (string) $settlement->lastMonthSalary->toDecimal());
        self::assertSame([['Indemnité compensatrice de congés payés', '1287.00', true], ['Indemnité de fin de contrat (CDD)', '1170.00', true]], array_map(static fn (array $i): array => [$i['label'], (string) $i['amount']->toDecimal(), $i['gross']], $settlement->indemnities));
        self::assertTrue($settlement->netPaid->equals($payslip->totals->netPay));
    }

    public function testAValidatedPayslipKeepsWhatItRead(): void
    {
        $dossier = (new \ReflectionClass(Dossier::class))->newInstanceWithoutConstructor();
        $employee = new Employee($dossier, 'S010', 'Perrin', 'Lucie', Sex::Female, new \DateTimeImmutable('1995-06-01'), ContractType::Permanent, new \DateTimeImmutable('2020-01-06'));
        $hire = new EmploymentTerms($employee, new \DateTimeImmutable('2020-01-06'), 'Dessinatrice', '', false, WorkingTime::FullTime, '151.67', Money::euros('2200.00'));
        $raise = new EmploymentTerms($employee, new \DateTimeImmutable('2026-04-01'), 'Dessinatrice', '', false, WorkingTime::FullTime, '151.67', Money::euros('2300.00'));
        $march = [new \DateTimeImmutable('2026-03-01')];
        $april = [new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-04-01')];

        self::assertNull(ValidatedPayslipLocks::employeeDeletion([]));
        self::assertNotNull(ValidatedPayslipLocks::employeeDeletion($march));
        // The raise of April read by April's payslip only.
        self::assertNull(ValidatedPayslipLocks::termsDeletion($raise, $march));
        self::assertSame('Les conditions du 01/04/2026 ont servi au bulletin validé d’avril 2026 : elles ne se suppriment pas.', ValidatedPayslipLocks::termsDeletion($raise, $april));
        // An exit on 31 May leaves March and April alone; one on 15 April, or moving it there, does not.
        self::assertNull(ValidatedPayslipLocks::exitChange(null, new \DateTimeImmutable('2026-05-31'), $april));
        self::assertNotNull(ValidatedPayslipLocks::exitChange(null, new \DateTimeImmutable('2026-04-15'), $april));
        self::assertNotNull(ValidatedPayslipLocks::exitChange(new \DateTimeImmutable('2026-05-31'), new \DateTimeImmutable('2026-04-15'), $april));
        self::assertSame('Le bulletin d’avril 2026 est validé : la sortie qu’il a lue ne change plus. Annulez d’abord le bulletin, si son mois est encore ouvert.', ValidatedPayslipLocks::exitChange(new \DateTimeImmutable('2026-04-30'), null, $april));
    }

    public function testTheAbsencesMonthByMonth(): void
    {
        $months = [
            '2026-01-01' => new PayslipElements(),
            '2026-02-01' => new PayslipElements(quantities: ['1210' => BigDecimal::of('56'), '1220' => BigDecimal::of('21')], unpaidDays: 7),
            '2026-03-01' => new PayslipElements(paidLeave: new PaidLeave('35', '5', '25', Money::euros('24000.00'))),
        ];
        $rows = EmployeeAbsences::of($months, self::standardPlan());

        self::assertSame(['2026-02', '2026-03'], array_map(static fn (array $r): string => $r['month']->format('Y-m'), $rows));
        self::assertSame([['Absence maladie : 56 h'], '21', 7, null], [$rows[0]['absences'], (string) $rows[0]['sickHours'], $rows[0]['unpaidDays'], $rows[0]['paidLeave']]);
        self::assertSame('5 j (35 h)', $rows[1]['paidLeave']);
    }
}
