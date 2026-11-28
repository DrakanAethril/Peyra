<?php

declare(strict_types=1);

namespace App\Controller\Payroll;

use App\Entity\Dossier\Dossier;
use App\Repository\Payroll\EmployeeRepository;
use App\Security\Voter\DossierRightVoter;
use App\Service\Payroll\Hiring\PersonnelRegister;
use App\Service\Shared\Pdf\PdfRenderer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Paie et social › Salariés › Registre unique du personnel (lot 4, step 4.2b): every person hired,
 * in the order of hiring (PersonnelRegister), on screen and as a PDF (A4 landscape). Read with
 * PAYROLL_READ.
 */
#[Route('/d/{dossier}/payroll/employees', requirements: ['dossier' => '\d+'])]
final class PersonnelRegisterController extends AbstractController
{
    use PayrollPdfTrait;

    #[Route('/register', name: 'app_payroll_register', methods: ['GET'])]
    public function index(Dossier $dossier, EmployeeRepository $employees): Response
    {
        return $this->render('payroll/hiring/register.html.twig', ['dossier' => $dossier, 'register_rows' => $this->rows($dossier, $employees)]);
    }

    #[Route('/register.pdf', name: 'app_payroll_register_pdf', methods: ['GET'])]
    public function pdfOf(Dossier $dossier, EmployeeRepository $employees, PdfRenderer $renderer): Response
    {
        $company = $dossier->getCompany();

        return self::pdf($renderer, 'payroll/hiring/register.pdf.html.twig', [
            'pdf' => true,
            'orientation' => 'landscape',
            'report_header' => [
                'company' => $company->getName(),
                'title' => 'Registre unique du personnel',
                'fiscalYear' => 'SIRET '.$company->getSiret(),
                'dossierDate' => $dossier->getDossierDate(),
            ],
            'register_rows' => $this->rows($dossier, $employees),
        ], 'registre du personnel', $dossier);
    }

    /** @return list<array{rank: int, employee: \App\Entity\Payroll\Employee, job: string, qualification: string, contract: string, exitOn: ?\DateTimeImmutable}> */
    private function rows(Dossier $dossier, EmployeeRepository $employees): array
    {
        if (!$this->isGranted(DossierRightVoter::PAYROLL_READ, $dossier)) {
            throw new NotFoundHttpException('The payroll is closed to this profile.');
        }

        return PersonnelRegister::rows($employees->findByDossier($dossier), $dossier->getDossierDate());
    }
}
