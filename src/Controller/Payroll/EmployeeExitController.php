<?php

declare(strict_types=1);

namespace App\Controller\Payroll;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\Payroll\Employee;
use App\Enum\Payroll\ExitReason;
use App\Repository\Payroll\EmployeeRepository;
use App\Security\Voter\DossierRightVoter;
use App\Service\Payroll\Exit\EmployeeExits;
use App\Service\Payroll\Exit\ExitDraft;
use App\Service\Payroll\Exit\FinalSettlement;
use App\Service\Payroll\Exit\JobPeriods;
use App\Service\Payroll\Payslip\PayslipBook;
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
 * An employee's end of contract (lot 4, step 4.2c; design-ux.md § 3.2 « Fin de contrat »): the
 * exit - its reason, the day it was notified, the last day of the contract - saved with F10 or
 * cancelled under PAYROLL_ENTER, and once it is recorded the work certificate generated as a PDF;
 * once the last payslip is validated, the reçu pour solde de tout compte (step 4.4c). Read with
 * PAYROLL_READ.
 */
#[Route('/d/{dossier}/payroll/employees/{number}', requirements: ['dossier' => '\d+', 'number' => '[A-Z0-9]{1,10}'])]
final class EmployeeExitController extends AbstractController
{
    use PayrollPdfTrait;

    #[Route('/exit', name: 'app_payroll_employee_exit', methods: ['GET', 'POST'])]
    public function index(Dossier $dossier, string $number, Request $request, EmployeeRepository $employees, EmployeeExits $exits, PayslipBook $book, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $employee = $this->employee($dossier, $number, $employees);
        $draft = ExitDraft::of($employee);
        if ($request->isMethod('POST')) {
            $this->mayChange($dossier, $request, 'exit-'.$employee->getId());
            $draft->reason = PostValue::trimmed($request, 'reason');
            $draft->notifiedOn = PostValue::trimmed($request, 'notifiedOn');
            $draft->exitOn = PostValue::trimmed($request, 'exitOn');
            $fields = $exits->check($draft, $employee);
            $refusal = null === $fields ? null : $exits->refusal($employee, $fields);
            if (null !== $refusal) {
                $draft->errors['exitOn'] = $refusal;
            } elseif (null !== $fields) {
                $changed = $exits->record($employee, $fields, $me);
                $entityManager->flush();
                $this->addFlash('status', $changed ? 'Sortie enregistrée.' : 'Rien à enregistrer : la sortie est inchangée.');

                return $this->redirectToRoute('app_payroll_employee_exit', ['dossier' => $dossier->getId(), 'number' => $number], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('payroll/exit/exit.html.twig', [
            'dossier' => $dossier,
            'employee' => $employee,
            'draft' => $draft,
            'last_payslip' => null === $employee->getExitOn() ? null : $book->payslipOf($employee, $employee->getExitOn()->modify('first day of this month')),
            'reason_options' => array_map(
                static fn (ExitReason $r): array => ['value' => $r->value, 'label' => $r->label()],
                array_values(array_filter(ExitReason::cases(), static fn (ExitReason $r): bool => $r->allows($employee->getContractType()))),
            ),
        ], new Response(null, [] === $draft->errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/exit/cancel', name: 'app_payroll_employee_exit_cancel', methods: ['POST'])]
    public function cancel(Dossier $dossier, string $number, Request $request, EmployeeRepository $employees, EmployeeExits $exits, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $employee = $this->employee($dossier, $number, $employees);
        $this->mayChange($dossier, $request, 'exit-cancel-'.$employee->getId());
        $refusal = $exits->refusal($employee, null);
        if (null !== $refusal) {
            $this->addFlash('status', $refusal);
        } elseif (null !== $employee->getExitOn()) {
            $exits->cancel($employee, $me);
            $entityManager->flush();
            $this->addFlash('status', 'Sortie annulée.');
        }

        return $this->redirectToRoute('app_payroll_employee_exit', ['dossier' => $dossier->getId(), 'number' => $number], Response::HTTP_SEE_OTHER);
    }

    #[Route('/work-certificate.pdf', name: 'app_payroll_employee_work_certificate', methods: ['GET'])]
    public function workCertificate(Dossier $dossier, string $number, EmployeeRepository $employees, PdfRenderer $renderer): Response
    {
        $employee = $this->employee($dossier, $number, $employees);
        $exitOn = $employee->getExitOn() ?? throw new NotFoundHttpException('No exit recorded.');

        return self::pdf($renderer, 'payroll/exit/work_certificate.pdf.html.twig', [
            'pdf' => true,
            'dossier' => $dossier,
            'company' => $dossier->getCompany(),
            'employee' => $employee,
            'periods' => JobPeriods::of(array_map(static fn ($terms): array => ['from' => $terms->getEffectiveFrom(), 'job' => $terms->getJobTitle()], $employee->getTerms()), $exitOn),
        ], 'certificat de travail '.$employee->getRegistrationNumber(), $dossier);
    }

    #[Route('/final-settlement.pdf', name: 'app_payroll_employee_final_settlement', methods: ['GET'])]
    public function finalSettlement(Dossier $dossier, string $number, EmployeeRepository $employees, PayslipBook $book, PdfRenderer $renderer): Response
    {
        $employee = $this->employee($dossier, $number, $employees);
        $exitOn = $employee->getExitOn() ?? throw new NotFoundHttpException('No exit recorded.');
        $payslip = $book->payslipOf($employee, $exitOn->modify('first day of this month'));
        $result = true === $payslip?->isValidated() ? $book->resultOf($payslip)['payslip'] : null;
        if (null === $payslip || null === $result) {
            throw new NotFoundHttpException('The receipt reads the last payslip, validated.');
        }

        return self::pdf($renderer, 'payroll/exit/final_settlement.pdf.html.twig', [
            'pdf' => true,
            'dossier' => $dossier,
            'company' => $dossier->getCompany(),
            'employee' => $employee,
            'month' => $payslip->getMonth(),
            'settlement' => FinalSettlement::of($result, $book->plan($dossier)),
        ], 'recu pour solde de tout compte '.$employee->getRegistrationNumber(), $dossier);
    }

    private function mayChange(Dossier $dossier, Request $request, string $tokenId): void
    {
        if (!$this->isGranted(DossierRightVoter::PAYROLL_ENTER, $dossier) || !$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not yours to change.');
        }
    }

    private function employee(Dossier $dossier, string $number, EmployeeRepository $employees): Employee
    {
        if (!$this->isGranted(DossierRightVoter::PAYROLL_READ, $dossier)) {
            throw new NotFoundHttpException('The payroll is closed to this profile.');
        }

        return $employees->findOneByNumber($dossier, $number) ?? throw new NotFoundHttpException('No such employee.');
    }
}
