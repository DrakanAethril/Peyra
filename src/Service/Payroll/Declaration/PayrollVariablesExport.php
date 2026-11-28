<?php

declare(strict_types=1);

namespace App\Service\Payroll\Declaration;

use App\Entity\Payroll\Employee;
use App\Entity\Payroll\MonthlyPayslip;
use App\Enum\Payroll\PayslipStatus;
use App\Service\Payroll\Payslip\PayslipElementField;
use App\Service\Payroll\Payslip\PayslipElements;
use App\Service\Shared\Export\Column;
use App\Service\Shared\Export\ColumnKind;
use App\Service\Shared\Export\ExportTable;
use App\Service\Shared\FrenchFormat;
use Brick\Math\BigDecimal;

/**
 * The employees and the elements of a month of pay as a spreadsheet (step 4.6; conception § « séances sur
 * d'autres outils », Silae: « Salariés et variables du mois en tableur »): one row per employee the month
 * pays - their fiche and contract on the pay day, then each field of the « Éléments du mois » card as it
 * was entered on their payslip, whatever its state (the variables are what is typed; an employee with no
 * payslip yet has empty ones). The same fields as the card (PayslipElementFields), in the plan's order;
 * when the month is the last of a contract for somebody (LastPayslipEntries, review 4.7), the end-of-contract
 * elements follow: the fixed-term contract's gross of the months before, the leave not taken, the severance.
 */
final class PayrollVariablesExport
{
    /**
     * @param list<PayslipElementField>                                    $fields        the card's fields for the dossier's plan
     * @param list<array{employee: Employee, payslip: ?MonthlyPayslip}> $employees     the employees the month pays, by name
     * @param list<string>                                                 $fixedTermCodes the plan's rubriques of the fixed-term indemnity, whose amount is the contract's gross before
     */
    public static function table(\DateTimeImmutable $month, \DateTimeImmutable $payDay, array $fields, bool $paidLeave, array $employees, bool $endOfContract = false, array $fixedTermCodes = []): ExportTable
    {
        $columns = [
            new Column('Matricule', ColumnKind::Code),
            new Column('Nom'),
            new Column('Prénom'),
            new Column('Sexe', ColumnKind::Code),
            new Column('Date de naissance', ColumnKind::Day),
            new Column('NIR', ColumnKind::Code, 17),
            new Column('Emploi'),
            new Column('Classification'),
            new Column('Statut', ColumnKind::Code),
            new Column('Contrat'),
            new Column('Entrée', ColumnKind::Day),
            new Column('Fin de contrat prévue', ColumnKind::Day),
            new Column('Sortie', ColumnKind::Day),
            new Column('Temps de travail'),
            new Column('Horaire mensuel', ColumnKind::Code),
            new Column('Salaire de base', ColumnKind::Amount),
            new Column('Taux de prélèvement à la source', ColumnKind::Code),
            new Column('Bulletin '.FrenchFormat::ofPeriod($month)),
        ];
        foreach ($fields as $field) {
            $columns[] = new Column($field->code.' '.$field->label.' ('.$field->unit.')', PayslipElementField::AMOUNT === $field->kind ? ColumnKind::Amount : ColumnKind::Code);
        }
        if ($paidLeave) {
            $columns[] = new Column('Congés payés pris (jours)', ColumnKind::Code);
            $columns[] = new Column('Congés payés pris (heures)', ColumnKind::Code);
        }
        $columns[] = new Column('Jours entiers sans aucun versement', ColumnKind::Code);
        if ($endOfContract) {
            $columns[] = new Column('Fin de CDD : brut du contrat des mois précédents', ColumnKind::Amount);
            $columns[] = new Column('Congés non pris : jours restants', ColumnKind::Code);
            $columns[] = new Column('Congés non pris : heures', ColumnKind::Code);
            $columns[] = new Column('Congés : jours acquis', ColumnKind::Code);
            $columns[] = new Column('Congés : brut de référence avant ce mois', ColumnKind::Amount);
            $columns[] = new Column('Indemnité de rupture : salaire de référence', ColumnKind::Amount);
            $columns[] = new Column('Indemnité convenue', ColumnKind::Amount);
            $columns[] = new Column('Brut de l’année précédant la rupture', ColumnKind::Amount);
        }

        $table = new ExportTable('Variables '.$month->format('Y-m'), $columns);
        foreach ($employees as ['employee' => $employee, 'payslip' => $payslip]) {
            $terms = $employee->termsAt($payDay);
            $rate = $employee->getWithholdingRate();
            $cells = [
                $employee->getRegistrationNumber(),
                $employee->getLastName(),
                $employee->getFirstName(),
                $employee->getSex()->value,
                $employee->getBirthDate(),
                $employee->getNir(),
                $terms->getJobTitle(),
                $terms->getClassification(),
                $terms->getStatusLabel(),
                $employee->getContractType()->label(),
                $employee->getHiredOn(),
                $employee->getContractEndsOn(),
                $employee->getExitOn(),
                $terms->getWorkingTime()->label(),
                PayslipElements::french(BigDecimal::of($terms->getMonthlyHours())),
                $terms->getBaseSalary(),
                null === $rate ? 'Taux neutre' : str_replace('.', ',', $rate).' %',
                ($payslip?->getStatus() ?? PayslipStatus::ToDo)->label(),
            ];
            $elements = null === $payslip ? new PayslipElements() : PayslipElements::fromArray($payslip->getElements());
            foreach ($fields as $field) {
                $cells[] = PayslipElementField::AMOUNT === $field->kind ? ($elements->amounts[$field->code] ?? null) : ('' === $elements->shown($field) ? null : $elements->shown($field));
            }
            if ($paidLeave) {
                $cells[] = null === $elements->paidLeave ? null : PayslipElements::french($elements->paidLeave->daysTaken);
                $cells[] = null === $elements->paidLeave ? null : PayslipElements::french($elements->paidLeave->hours);
            }
            $cells[] = 0 === $elements->unpaidDays ? null : (string) $elements->unpaidDays;
            if ($endOfContract) {
                $fixedTerm = null;
                foreach ($fixedTermCodes as $code) {
                    $fixedTerm ??= $elements->amounts[$code] ?? null;
                }
                $leave = $elements->compensatoryLeave;
                $severance = $elements->severance;
                array_push(
                    $cells,
                    $fixedTerm,
                    null === $leave ? null : PayslipElements::french($leave->daysTaken),
                    null === $leave ? null : PayslipElements::french($leave->hours),
                    null === $leave ? null : PayslipElements::french($leave->daysAcquired),
                    $leave?->referenceGross,
                    $severance?->referenceSalary,
                    $severance?->agreed,
                    $severance?->previousYearGross,
                );
            }
            $table->add($cells);
        }

        return $table;
    }
}
