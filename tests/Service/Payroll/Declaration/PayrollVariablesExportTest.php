<?php

declare(strict_types=1);

namespace App\Tests\Service\Payroll\Declaration;

use App\Entity\Dossier\Dossier;
use App\Entity\Payroll\Employee;
use App\Entity\Payroll\EmploymentTerms;
use App\Entity\Payroll\MonthlyPayslip;
use App\Enum\Payroll\ContractType;
use App\Enum\Payroll\ExitReason;
use App\Enum\Payroll\Sex;
use App\Enum\Payroll\WorkingTime;
use App\Service\Payroll\Declaration\PayrollVariablesExport;
use App\Service\Payroll\Engine\PaidLeave;
use App\Service\Payroll\Payslip\PayslipElements;
use App\Service\Shared\Money;
use PHPUnit\Framework\TestCase;

/**
 * The variables of a month in a spreadsheet (step 4.6): the last payslip's own elements follow the card's
 * when the month ends a contract (review 4.7) - Yanis Chabert's fixed-term contract, ended on 30/09/2026.
 */
final class PayrollVariablesExportTest extends TestCase
{
    public function testTheEndOfContractElementsOfTheLastPayslip(): void
    {
        $dossier = (new \ReflectionClass(Dossier::class))->newInstanceWithoutConstructor();
        $yanis = new Employee($dossier, 'S011', 'Chabert', 'Yanis', Sex::Male, new \DateTimeImmutable('2005-02-14'), ContractType::FixedTerm, new \DateTimeImmutable('2026-04-01'));
        $yanis->addTerms(new EmploymentTerms($yanis, new \DateTimeImmutable('2026-04-01'), 'Aide-menuisier', '', false, WorkingTime::FullTime, '151.67', Money::euros('1950.00')));
        $yanis->leave(ExitReason::FixedTermEnd, null, new \DateTimeImmutable('2026-09-30'));
        $month = new \DateTimeImmutable('2026-09-01');
        $payslip = new MonthlyPayslip($yanis, $month);
        $payslip->calculated(new PayslipElements(amounts: ['1500' => Money::euros('9750.00')], compensatoryLeave: new PaidLeave('87.5', '15', '15', Money::euros('9750.00')))->toArray(), [], false);

        $table = PayrollVariablesExport::table($month, new \DateTimeImmutable('2026-09-30'), [], false, [['employee' => $yanis, 'payslip' => $payslip]], true, ['1500']);
        $columns = array_map(static fn ($column): string => $column->title, $table->columns);
        $row = $table->rows()[0];
        $at = static fn (string $label): mixed => $row[array_search($label, $columns, true)];

        self::assertEquals(Money::euros('9750.00'), $at('Fin de CDD : brut du contrat des mois précédents'));
        self::assertSame(['15', '87,5', '15'], [$at('Congés non pris : jours restants'), $at('Congés non pris : heures'), $at('Congés : jours acquis')]);
        self::assertEquals(Money::euros('9750.00'), $at('Congés : brut de référence avant ce mois'));
        self::assertNull($at('Indemnité de rupture : salaire de référence'));
        self::assertNotContains('Fin de CDD : brut du contrat des mois précédents', array_map(static fn ($column): string => $column->title, PayrollVariablesExport::table($month, new \DateTimeImmutable('2026-09-30'), [], false, [['employee' => $yanis, 'payslip' => $payslip]])->columns));
    }
}
