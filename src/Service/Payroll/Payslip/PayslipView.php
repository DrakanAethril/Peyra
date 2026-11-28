<?php

declare(strict_types=1);

namespace App\Service\Payroll\Payslip;

use App\Enum\Payroll\PayrollItemKind;
use App\Enum\Payroll\PayslipSection;
use App\Service\Payroll\Engine\Payslip;
use App\Service\Payroll\Engine\PayslipRow;

/**
 * The calculated payslip as the screen lays it (step 4.4a, maquette 4): one row per rubrique - the
 * unit Sage edits and explains -, the elements of gross pay, the gross subtotal, the contributions
 * under the sections of the clarified model in its order, the items not subject and the deductions
 * from the net. The PDF groups the rubriques by line of the clarified model (step 4.4b).
 *
 * @phpstan-type ViewBlock array{heading: ?string, rows: list<PayslipRow>}
 */
final class PayslipView
{
    /** @return array{gross: list<PayslipRow>, contributions: list<ViewBlock>, after: list<ViewBlock>} */
    public static function of(Payslip $payslip): array
    {
        $gross = [];
        $sections = [];
        $loose = [];
        $notSubject = [];
        $deductions = [];
        foreach ($payslip->rows as $row) {
            switch ($row->kind) {
                case PayrollItemKind::Gross:
                    $gross[] = $row;
                    break;
                case PayrollItemKind::Contribution:
                    if (null === $row->line) {
                        $loose[] = $row;
                    } else {
                        $sections[$row->line->section()->value][] = $row;
                    }
                    break;
                case PayrollItemKind::NotSubject:
                    $notSubject[] = $row;
                    break;
                default:
                    $deductions[] = $row;
            }
        }
        $contributions = [];
        foreach (PayslipSection::cases() as $section) {
            if (isset($sections[$section->value])) {
                $contributions[] = ['heading' => $section->label(), 'rows' => $sections[$section->value]];
            }
        }
        if ([] !== $loose) {
            $contributions[] = ['heading' => 'Autres cotisations', 'rows' => $loose];
        }
        $after = [];
        if ([] !== $notSubject) {
            $after[] = ['heading' => PayrollItemKind::NotSubject->heading(), 'rows' => $notSubject];
        }
        if ([] !== $deductions) {
            $after[] = ['heading' => PayrollItemKind::Deduction->heading(), 'rows' => $deductions];
        }

        return ['gross' => $gross, 'contributions' => $contributions, 'after' => $after];
    }
}
