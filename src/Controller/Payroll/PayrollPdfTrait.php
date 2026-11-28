<?php

declare(strict_types=1);

namespace App\Controller\Payroll;

use App\Entity\Dossier\Dossier;
use App\Entity\Payroll\MonthlyPayslip;
use App\Service\Payroll\Employee\Nir;
use App\Service\Payroll\Engine\Payslip;
use App\Service\Payroll\Payslip\PayslipBook;
use App\Service\Payroll\Payslip\PayslipPrintLines;
use App\Service\Shared\Pdf\PdfRenderer;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\String\Slugger\AsciiSlugger;

/** A payroll document as a PDF shown in the browser, named after the document, the dossier and its date. */
trait PayrollPdfTrait
{
    /** @param array<string, mixed> $context */
    private static function pdf(PdfRenderer $renderer, string $template, array $context, string $name, Dossier $dossier): Response
    {
        $file = (new AsciiSlugger('fr'))->slug($name.' '.$dossier->getName().' '.$dossier->getDossierDate()->format('Y-m-d'))->lower()->toString().'.pdf';

        return new Response($renderer->renderTemplate($template, $context), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $file),
        ]);
    }

    /** A validated payslip printed on the clarified model (step 4.4b), a duplicata once sent. */
    private static function payslipPdf(PdfRenderer $renderer, MonthlyPayslip $payslip, Payslip $result): Response
    {
        $dossier = $payslip->getDossier();
        $employee = $payslip->getEmployee();
        $month = $payslip->getMonth();
        $day = PayslipBook::payDay($month);

        return self::pdf($renderer, 'payroll/payslip.pdf.html.twig', [
            'pdf' => true,
            'dossier' => $dossier,
            'company' => $dossier->getCompany(),
            'employee' => $employee,
            'terms' => $employee->termsAt($day),
            'nir' => null === $employee->getNir() ? null : Nir::format($employee->getNir()),
            'month' => $month,
            'pay_day' => $day,
            'payslip' => $payslip,
            'result' => $result,
            'lines' => PayslipPrintLines::of($result),
            'duplicate' => null !== $payslip->getSentOn(),
        ], ($payslip->getSentOn() ? 'duplicata bulletin ' : 'bulletin ').$employee->getRegistrationNumber().' '.$month->format('Y-m'), $dossier);
    }
}
