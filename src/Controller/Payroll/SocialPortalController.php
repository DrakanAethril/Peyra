<?php

declare(strict_types=1);

namespace App\Controller\Payroll;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\Payroll\Employee;
use App\Entity\Payroll\HiringDeclaration;
use App\Repository\Payroll\EmployeeRepository;
use App\Security\Voter\DossierRightVoter;
use App\Service\Accounting\Entry\AssistanceLevels;
use App\Service\Payroll\Hiring\HiringFormalities;
use App\Service\Shared\Assistance\Assistance;
use App\Service\Shared\Pdf\PdfRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The simulated social portal (design-ux.md A13: its own grey frame, « Simulation : rien n'est
 * transmis »), its DPAE for now (step 4.2b): the declaration as the portal receives it from the
 * fiche, « Déposer la DPAE » (Ctrl Entrée after the confirmation bar, PAYROLL_ENTER) - rejected
 * with its motives (explained at level G) and nothing kept, or accepted with its acknowledgement,
 * shown and printed as a PDF. The DSN joins it at step 4.6.
 */
#[Route('/d/{dossier}/payroll/social-portal/dpae/{number}', requirements: ['dossier' => '\d+', 'number' => '[A-Z0-9]{1,10}'])]
final class SocialPortalController extends AbstractController
{
    use PayrollPdfTrait;

    #[Route('', name: 'app_social_portal_dpae', methods: ['GET', 'POST'])]
    public function dpae(Dossier $dossier, string $number, Request $request, EmployeeRepository $employees, HiringFormalities $formalities, AssistanceLevels $levels, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $employee = $this->employee($dossier, $number, $employees);
        if (null !== $formalities->declaration($employee)) {
            return $this->redirectToRoute('app_social_portal_dpae_receipt', ['dossier' => $dossier->getId(), 'number' => $number], Response::HTTP_SEE_OTHER);
        }
        $faults = [];
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            if (!$this->isGranted(DossierRightVoter::PAYROLL_ENTER, $dossier) || !$this->isCsrfTokenValid('dpae-'.$employee->getId(), $request->request->getString('_token'))) {
                throw new NotFoundHttpException('Not yours to change.');
            }
            $outcome = $formalities->declare($employee, $dossier->getDossierDate(), $me);
            if ($outcome instanceof HiringDeclaration) {
                $entityManager->flush();

                return $this->redirectToRoute('app_social_portal_dpae_receipt', ['dossier' => $dossier->getId(), 'number' => $number], Response::HTTP_SEE_OTHER);
            }
            $faults = $outcome;
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return $this->render('payroll/hiring/dpae.html.twig', [
            'dossier' => $dossier,
            'employee' => $employee,
            'content' => HiringFormalities::content($employee),
            'faults' => $faults,
            'explained' => Assistance::at($levels->for($dossier))->explainsPortalRejections(),
            'opens_on' => $formalities->checklist($employee, $dossier->getDossierDate())->dpaeOpensOn,
        ], new Response(status: $status));
    }

    #[Route('/receipt', name: 'app_social_portal_dpae_receipt', methods: ['GET'])]
    public function receipt(Dossier $dossier, string $number, EmployeeRepository $employees, HiringFormalities $formalities): Response
    {
        $employee = $this->employee($dossier, $number, $employees);

        return $this->render('payroll/hiring/dpae_receipt.html.twig', [
            'dossier' => $dossier,
            'employee' => $employee,
            'declaration' => $formalities->declaration($employee) ?? throw new NotFoundHttpException('Not declared.'),
        ]);
    }

    #[Route('/receipt.pdf', name: 'app_social_portal_dpae_receipt_pdf', methods: ['GET'])]
    public function receiptPdf(Dossier $dossier, string $number, EmployeeRepository $employees, HiringFormalities $formalities, PdfRenderer $renderer): Response
    {
        $employee = $this->employee($dossier, $number, $employees);
        $declaration = $formalities->declaration($employee) ?? throw new NotFoundHttpException('Not declared.');

        return self::pdf($renderer, 'payroll/hiring/dpae_receipt.pdf.html.twig', ['pdf' => true, 'declaration' => $declaration, 'employee' => $employee], 'accuse DPAE '.$number, $dossier);
    }

    private function employee(Dossier $dossier, string $number, EmployeeRepository $employees): Employee
    {
        if (!$this->isGranted(DossierRightVoter::PAYROLL_READ, $dossier)) {
            throw new NotFoundHttpException('The payroll is closed to this profile.');
        }

        return $employees->findOneByNumber($dossier, $number) ?? throw new NotFoundHttpException('No such employee.');
    }
}
