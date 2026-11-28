<?php

declare(strict_types=1);

namespace App\Controller\Payroll;

use App\Entity\Dossier\Dossier;
use App\Security\Voter\DossierRightVoter;
use App\Service\Payroll\Declaration\PayrollVariablesExport;
use App\Service\Payroll\Payslip\PayslipBook;
use App\Service\Payroll\Payslip\PayslipElementFields;
use App\Service\Shared\Export\ExportFormat;
use App\Service\Shared\Export\TableExport;
use App\Service\Shared\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Paie et social › Bulletins › « Exporter les variables » (step 4.6): the employees the month pays and the
 * elements entered on their payslips, as a spreadsheet (PayrollVariablesExport) - what a session on Silae
 * starts from. The month paid, or `?month=2026-03`. Read with PAYROLL_READ.
 */
final class PayrollVariablesExportController extends AbstractController
{
    #[Route('/d/{dossier}/payroll/payslips/export.xlsx', name: 'app_payroll_payslips_export', requirements: ['dossier' => '\d+'], methods: ['GET'])]
    public function export(Dossier $dossier, Request $request, PayslipBook $book): Response
    {
        if (!$this->isGranted(DossierRightVoter::PAYROLL_READ, $dossier)) {
            throw new NotFoundHttpException('The payroll is closed to this profile.');
        }
        $asked = QueryValue::trimmed($request, 'month');
        $month = 1 === preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $asked) ? new \DateTimeImmutable($asked.'-01') : $book->month($dossier);
        $payslips = $book->payslipsOf($dossier, $month);
        $employees = [];
        foreach ($book->employeesOf($dossier, $month) as $employee) {
            $employees[] = ['employee' => $employee, 'payslip' => $payslips[(int) $employee->getId()] ?? null];
        }
        $plan = $book->plan($dossier);
        $table = PayrollVariablesExport::table($month, PayslipBook::payDay($month), PayslipElementFields::of($plan), PayslipElementFields::takesPaidLeave($plan), $employees);

        return TableExport::response($table, ExportFormat::Xlsx, 'variables de paie '.$month->format('Y-m').' '.$dossier->getName());
    }
}
