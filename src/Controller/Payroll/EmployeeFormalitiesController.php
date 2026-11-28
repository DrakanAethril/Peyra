<?php

declare(strict_types=1);

namespace App\Controller\Payroll;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\Payroll\Employee;
use App\Enum\Payroll\PayrollBodyKind;
use App\Repository\Payroll\EmployeeRepository;
use App\Repository\Payroll\PayrollEstablishmentRepository;
use App\Security\Voter\DossierRightVoter;
use App\Service\Payroll\Employee\Nir;
use App\Service\Payroll\Hiring\HiringFormalities;
use App\Service\Shared\DateValue;
use App\Service\Shared\Pdf\PdfRenderer;
use App\Service\Shared\PostValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * An employee's hiring formalities (lot 4, step 4.2b; design-ux.md § 3.2 « Formalités », gabarit
 * E): the DPAE - to deposit on the simulated social portal, or its acknowledgement -, the
 * employment contract generated as a PDF from the fiche, the information and prevention visit's day
 * and the affiliations to the dossier's bodies, saved with F10 under PAYROLL_ENTER. Read with
 * PAYROLL_READ.
 */
#[Route('/d/{dossier}/payroll/employees/{number}', requirements: ['dossier' => '\d+', 'number' => '[A-Z0-9]{1,10}'])]
final class EmployeeFormalitiesController extends AbstractController
{
    use PayrollPdfTrait;

    #[Route('/formalities', name: 'app_payroll_employee_formalities', methods: ['GET', 'POST'])]
    public function index(Dossier $dossier, string $number, Request $request, EmployeeRepository $employees, HiringFormalities $formalities, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $employee = $this->employee($dossier, $number, $employees);
        if ($request->isMethod('POST')) {
            if (!$this->isGranted(DossierRightVoter::PAYROLL_ENTER, $dossier) || !$this->isCsrfTokenValid('formalities-'.$employee->getId(), $request->request->getString('_token'))) {
                throw new NotFoundHttpException('Not yours to change.');
            }
            $affiliations = [];
            foreach (PostValue::stringsById($request, 'affiliation') as $id => $day) {
                $affiliations[$id] = DateValue::parse($day);
            }
            $changed = $formalities->record($employee, DateValue::parse(PostValue::trimmed($request, 'visitOn')), $affiliations, $me);
            $entityManager->flush();
            $this->addFlash('status', $changed ? 'Formalités enregistrées.' : 'Rien à enregistrer : les formalités sont inchangées.');

            return $this->redirectToRoute('app_payroll_employee_formalities', ['dossier' => $dossier->getId(), 'number' => $number], Response::HTTP_SEE_OTHER);
        }
        $today = $dossier->getDossierDate();

        return $this->render('payroll/hiring/formalities.html.twig', [
            'dossier' => $dossier,
            'employee' => $employee,
            'checklist' => $formalities->checklist($employee, $today),
            'declaration' => $formalities->declaration($employee),
            'bodies' => $formalities->bodies($dossier),
            'affiliation_days' => $formalities->affiliationDays($employee),
        ]);
    }

    #[Route('/contract.pdf', name: 'app_payroll_employee_contract', methods: ['GET'])]
    public function contract(Dossier $dossier, string $number, EmployeeRepository $employees, HiringFormalities $formalities, PayrollEstablishmentRepository $establishments, PdfRenderer $renderer): Response
    {
        $employee = $this->employee($dossier, $number, $employees);
        $bodies = array_values(array_filter($formalities->bodies($dossier), static fn ($body): bool => \in_array($body->getKind(), [PayrollBodyKind::Retirement, PayrollBodyKind::Provident], true)));

        return self::pdf($renderer, 'payroll/hiring/contract.pdf.html.twig', [
            'pdf' => true,
            'dossier' => $dossier,
            'company' => $dossier->getCompany(),
            'employee' => $employee,
            'nir' => null === $employee->getNir() ? null : Nir::format($employee->getNir()),
            'terms' => $employee->getTerms()[0],
            'collective_hours' => $establishments->findOfDossier($dossier)?->getMonthlyHours(),
            'bodies' => $bodies,
        ], 'contrat de travail '.$employee->getRegistrationNumber(), $dossier);
    }

    private function employee(Dossier $dossier, string $number, EmployeeRepository $employees): Employee
    {
        if (!$this->isGranted(DossierRightVoter::PAYROLL_READ, $dossier)) {
            throw new NotFoundHttpException('The payroll is closed to this profile.');
        }

        return $employees->findOneByNumber($dossier, $number) ?? throw new NotFoundHttpException('No such employee.');
    }
}
