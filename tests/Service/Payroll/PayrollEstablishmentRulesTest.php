<?php

declare(strict_types=1);

namespace App\Tests\Service\Payroll;

use App\Enum\Payroll\HeadcountCondition;
use App\Service\Payroll\Settings\PayrollEstablishmentDraft;
use App\Service\Payroll\Settings\PayrollEstablishmentRules;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

/** The establishment of the payroll (step 4.1) and the headcount thresholds it feeds. */
final class PayrollEstablishmentRulesTest extends TestCase
{
    public function testFiguresAsTypedBecomeExactTexts(): void
    {
        $draft = $this->draft('8,69', '2,10', '', '151,67');
        $fields = PayrollEstablishmentRules::check($draft);

        self::assertNotNull($fields, implode(' ', $draft->errors));
        self::assertSame('8.69', $fields->headcount);
        self::assertSame('2.1', $fields->workAccidentRate);
        self::assertNull($fields->mobilityRate, 'Empty: no mobility payment is due.');
        self::assertSame('151.67', $fields->monthlyHours);
    }

    public function testEachFaultUnderItsField(): void
    {
        $draft = $this->draft('', '0', '120', '400');

        self::assertNull(PayrollEstablishmentRules::check($draft));
        self::assertSame('Indiquez l’effectif.', $draft->error('headcount'));
        self::assertSame('Un taux est compris entre 0 et 100 %.', $draft->error('workAccidentRate'));
        self::assertNotNull($draft->error('mobilityRate'));
        self::assertNotNull($draft->error('monthlyHours'));

        $draft = $this->draft('8,695', '2,1', '', '151,67');
        self::assertNull(PayrollEstablishmentRules::check($draft));
        self::assertSame('2 décimales au plus.', $draft->error('headcount'));
    }

    public function testTheThresholdsOfTheHeadcount(): void
    {
        $vasseur = BigDecimal::of('8.69');
        self::assertTrue(HeadcountCondition::Below11->holds($vasseur));
        self::assertFalse(HeadcountCondition::From11->holds($vasseur));
        self::assertTrue(HeadcountCondition::From11->holds(BigDecimal::of('11')));
        self::assertTrue(HeadcountCondition::Below20->holds(BigDecimal::of('19.99')));
        self::assertTrue(HeadcountCondition::From50->holds(BigDecimal::of('50')));
        self::assertFalse(HeadcountCondition::From50->holds(BigDecimal::of('49.99')));
        self::assertTrue(HeadcountCondition::All->holds(BigDecimal::zero()));
    }

    private function draft(string $headcount, string $accident, string $mobility, string $hours): PayrollEstablishmentDraft
    {
        $draft = new PayrollEstablishmentDraft();
        [$draft->headcount, $draft->workAccidentRate, $draft->mobilityRate, $draft->monthlyHours] = [$headcount, $accident, $mobility, $hours];

        return $draft;
    }
}
