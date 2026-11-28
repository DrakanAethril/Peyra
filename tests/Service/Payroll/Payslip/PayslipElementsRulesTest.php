<?php

declare(strict_types=1);

namespace App\Tests\Service\Payroll\Payslip;

use App\Entity\Dossier\Dossier;
use App\Entity\Payroll\Employee;
use App\Enum\Payroll\ContractType;
use App\Enum\Payroll\Sex;
use App\Service\Payroll\Payslip\PayslipBook;
use App\Service\Payroll\Payslip\PayslipElementField;
use App\Service\Payroll\Payslip\PayslipElementFields;
use App\Service\Payroll\Payslip\PayslipElements;
use App\Service\Payroll\Payslip\PayslipElementsRules;
use App\Tests\Service\Payroll\Engine\StandardPlanFixture;
use PHPUnit\Framework\TestCase;

/**
 * The « Éléments du mois » card (step 4.4a): its fields read from the standard plan, what a typed card
 * becomes, the refusals, the round trip through the JSON column, and which employees a month pays.
 */
final class PayslipElementsRulesTest extends TestCase
{
    use StandardPlanFixture;

    public function testTheFieldsOfTheStandardPlan(): void
    {
        $fields = PayslipElementFields::of(self::standardPlan());
        self::assertSame([
            'rate.1010', 'amount.1020', 'quantity.1100', 'quantity.1110', 'quantity.1120', 'quantity.1130', 'quantity.1200', 'quantity.1210',
            'quantity.1220', 'amount.1220', 'amount.1400', 'quantity.1410', 'quantity.3000', 'amount.3010', 'amount.4100',
        ], array_map(static fn (PayslipElementField $f): string => $f->key(), $fields));
        self::assertSame('Maintien de salaire maladie : indemnités journalières reçues', $fields[9]->label);
        self::assertTrue(PayslipElementFields::takesPaidLeave(self::standardPlan()));
    }

    public function testATypedCardBecomesTheVariablesOfTheMonth(): void
    {
        $plan = self::standardPlan();
        $read = PayslipElementsRules::read(PayslipElementFields::of($plan), true, [
            'rate.1010' => '3', 'amount.1020' => '150,5', 'quantity.1100' => '4,25', 'quantity.1110' => '', 'amount.4100' => '0',
            'paidLeave.hours' => '14', 'paidLeave.daysTaken' => '2', 'paidLeave.daysAcquired' => '25', 'paidLeave.referenceGross' => '28 800',
            'unpaidDays' => '3',
        ]);
        self::assertSame([], $read['errors']);
        $elements = $read['elements'] ?? self::fail('read');
        $variables = $elements->toVariables();
        self::assertSame('3', (string) $variables->rate('1010'));
        self::assertSame(15050, $variables->amount('1020')?->cents);
        self::assertSame('4.25', (string) $variables->quantity('1100'));
        self::assertNull($variables->quantity('1110'), 'Empty: nothing this month.');
        self::assertNull($variables->amount('4100'), 'Zero: nothing this month.');
        self::assertSame(2_880_000, $variables->paidLeave?->referenceGross->cents);
        self::assertSame(3, $variables->unpaidDays);

        $back = PayslipElements::fromArray(json_decode((string) json_encode($elements->toArray()), true));
        self::assertEquals($elements->toArray(), $back->toArray(), 'The JSON column gives the same elements back.');
        self::assertSame('150,50', $back->shown(new PayslipElementField('1020', 'Prime', PayslipElementField::AMOUNT, '€')));
    }

    public function testWhatTheCardRefuses(): void
    {
        $fields = PayslipElementFields::of(self::standardPlan());
        $read = PayslipElementsRules::read($fields, true, [
            'rate.1010' => '120', 'amount.1020' => '12,345', 'quantity.1100' => '-2', 'quantity.1200' => 'abc',
            'paidLeave.hours' => '14', 'unpaidDays' => '32',
        ]);
        self::assertNull($read['elements']);
        self::assertSame('Un pourcentage est compris entre 0 et 100.', $read['errors']['rate.1010']);
        self::assertSame('2 décimales au plus.', $read['errors']['amount.1020']);
        self::assertStringStartsWith('Un élément du mois n’est pas négatif', $read['errors']['quantity.1100']);
        self::assertSame('Ce nombre n’est pas lisible. Écrivez par exemple 6,90.', $read['errors']['quantity.1200']);
        self::assertStringStartsWith('Des congés payés pris se décrivent', $read['errors']['paidLeave']);
        self::assertSame('Un nombre de jours entiers du mois, de 0 à 31.', $read['errors']['unpaidDays']);

        $read = PayslipElementsRules::read($fields, true, ['paidLeave.hours' => '14', 'paidLeave.daysTaken' => '30', 'paidLeave.daysAcquired' => '25', 'paidLeave.referenceGross' => '1000']);
        self::assertSame('Les jours pris sont plus de zéro et pas plus que les jours acquis.', $read['errors']['paidLeave']);
    }

    public function testTheEmployeesAMonthPays(): void
    {
        $march = new \DateTimeImmutable('2026-03-01');
        self::assertSame('2026-03-31', PayslipBook::payDay($march)->format('Y-m-d'));
        $dossier = $this->createStub(Dossier::class);
        $hiredOn = static fn (string $day): Employee => new Employee($dossier, 'S900', 'Essai', 'Léa', Sex::Female, new \DateTimeImmutable('1990-01-01'), ContractType::Permanent, new \DateTimeImmutable($day));
        self::assertTrue(PayslipBook::paysIn($hiredOn('2026-03-31'), $march), 'Hired on the last day.');
        self::assertFalse(PayslipBook::paysIn($hiredOn('2026-04-01'), $march), 'Hired the month after.');
        self::assertTrue(PayslipBook::paysIn($hiredOn('2020-01-01'), $march));
    }
}
