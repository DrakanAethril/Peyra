<?php

declare(strict_types=1);

namespace App\Controller\Payroll;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\Payroll\Employee;
use App\Entity\Payroll\EmploymentTerms;
use App\Enum\Payroll\ContractType;
use App\Enum\Payroll\Sex;
use App\Enum\Payroll\WorkingTime;
use App\Repository\Payroll\EmployeeRepository;
use App\Repository\Payroll\PayrollEstablishmentRepository;
use App\Security\Voter\DossierRightVoter;
use App\Service\Payroll\Employee\EmployeeDraft;
use App\Service\Payroll\Employee\EmployeeSheet;
use App\Service\Payroll\Employee\EmploymentHistory;
use App\Service\Shared\DateValue;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\PostValue;
use App\Service\Shared\QueryValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The fiche of an employee (lot 4, step 4.2a; gabarit F): civil status, contract, the terms in force
 * at the dossier's date - or those of the day `?from=` names - and the withholding rate, then the
 * history. F10 saves under PAYROLL_ENTER; « À compter du » says whether a change corrects the terms
 * of that day or starts new ones. Anybody else who reads the payroll sees the facts. The address is
 * the matricule.
 */
#[Route('/d/{dossier}/payroll/employees', requirements: ['dossier' => '\d+'])]
final class EmployeeController extends AbstractController
{
    private const string NUMBER = '[A-Z0-9]{1,10}';

    #[Route('/new', name: 'app_payroll_employee_new', methods: ['GET', 'POST'])]
    public function create(Dossier $dossier, Request $request, EmployeeSheet $sheet, PayrollEstablishmentRepository $establishments, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        if (!$this->isGranted(DossierRightVoter::PAYROLL_ENTER, $dossier)) {
            throw new NotFoundHttpException('Not yours to change.');
        }
        $draft = $sheet->blank($dossier);
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            $this->read($request, $draft, 'employee-new-'.$dossier->getId());
            $fields = $sheet->check($draft, $dossier);
            if (null !== $fields) {
                $employee = $sheet->create($dossier, $fields, $me);
                $entityManager->flush();
                $this->addFlash('status', \sprintf('Salarié %s %s créé.', $fields->registrationNumber, $employee->getFullName()));

                return $this->redirectToRoute('app_payroll_employee', ['dossier' => $dossier->getId(), 'number' => $fields->registrationNumber], Response::HTTP_SEE_OTHER);
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return $this->render('payroll/employee.html.twig', $this->view($dossier, $draft, null, null, $establishments), new Response(status: $status));
    }

    #[Route('/{number}', name: 'app_payroll_employee', requirements: ['number' => self::NUMBER], methods: ['GET', 'POST'])]
    public function edit(Dossier $dossier, string $number, Request $request, EmployeeRepository $employees, EmployeeSheet $sheet, PayrollEstablishmentRepository $establishments, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $employee = $this->employee($dossier, $number, $employees);
        $from = DateValue::parse(QueryValue::trimmed($request, 'from'));
        $shown = (null === $from ? null : $employee->termsStartingOn($from)) ?? $employee->termsAt($dossier->getDossierDate());
        $draft = EmployeeDraft::of($employee, $shown);
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            if (!$this->isGranted(DossierRightVoter::PAYROLL_ENTER, $dossier)) {
                throw new NotFoundHttpException('Not yours to change.');
            }
            $this->read($request, $draft, 'employee-'.$employee->getId());
            $fields = $sheet->check($draft, $dossier, $employee);
            if (null !== $fields) {
                $changed = $sheet->update($employee, $fields, $me);
                $entityManager->flush();
                $this->addFlash('status', $changed ? \sprintf('Salarié %s enregistré.', $fields->registrationNumber) : 'Rien à enregistrer : le salarié est inchangé.');

                return $this->redirectToRoute('app_payroll_employee', ['dossier' => $dossier->getId(), 'number' => $fields->registrationNumber, 'from' => $fields->termsFrom->format('Y-m-d')], Response::HTTP_SEE_OTHER);
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return $this->render('payroll/employee.html.twig', $this->view($dossier, $draft, $employee, $shown, $establishments), new Response(status: $status));
    }

    #[Route('/{number}/terms/{from}/delete', name: 'app_payroll_employee_terms_delete', requirements: ['number' => self::NUMBER, 'from' => '\d{4}-\d{2}-\d{2}'], methods: ['POST'])]
    public function deleteTerms(Dossier $dossier, string $number, string $from, Request $request, EmployeeRepository $employees, EmployeeSheet $sheet, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $employee = $this->employee($dossier, $number, $employees);
        $day = DateValue::parse($from);
        $terms = null === $day ? null : $employee->termsStartingOn($day);
        if (null === $terms || !$this->isGranted(DossierRightVoter::PAYROLL_ENTER, $dossier) || !$this->isCsrfTokenValid('employee-terms-delete-'.$terms->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not yours to change.');
        }
        $refusal = EmployeeSheet::termsDeletionRefusal($terms);
        if (null === $refusal) {
            $sheet->deleteTerms($terms, $me);
            $entityManager->flush();
        }
        $this->addFlash('status', $refusal ?? \sprintf('Conditions du %s supprimées.', FrenchFormat::date($terms->getEffectiveFrom())));

        return $this->redirectToRoute('app_payroll_employee', ['dossier' => $dossier->getId(), 'number' => $number], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{number}/delete', name: 'app_payroll_employee_delete', requirements: ['number' => self::NUMBER], methods: ['POST'])]
    public function delete(Dossier $dossier, string $number, Request $request, EmployeeRepository $employees, EmployeeSheet $sheet, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $employee = $this->employee($dossier, $number, $employees);
        if (!$this->isGranted(DossierRightVoter::PAYROLL_ENTER, $dossier) || !$this->isCsrfTokenValid('employee-delete-'.$employee->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not yours to change.');
        }
        $name = $employee->getFullName();
        $sheet->delete($employee, $me);
        $entityManager->flush();
        $this->addFlash('status', \sprintf('Salarié %s %s supprimé.', $number, $name));

        return $this->redirectToRoute('app_payroll_employees', ['dossier' => $dossier->getId()], Response::HTTP_SEE_OTHER);
    }

    private function employee(Dossier $dossier, string $number, EmployeeRepository $employees): Employee
    {
        if (!$this->isGranted(DossierRightVoter::PAYROLL_READ, $dossier)) {
            throw new NotFoundHttpException('The payroll is closed to this profile.');
        }

        return $employees->findOneByNumber($dossier, $number) ?? throw new NotFoundHttpException('No such employee.');
    }

    private function read(Request $request, EmployeeDraft $draft, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not yours to change.');
        }
        foreach (['registrationNumber', 'lastName', 'firstName', 'sex', 'birthDate', 'birthPlace', 'nationality', 'nir', 'street', 'postalCode', 'city',
            'withholdingRate', 'contractType', 'hiredOn', 'contractEndsOn', 'fixedTermReason', 'diploma', 'trainingCentre', 'termsFrom',
            'jobTitle', 'classification', 'status', 'workingTime', 'monthlyHours'] as $field) {
            $draft->{$field} = PostValue::trimmed($request, $field);
        }
        $draft->baseSalary = PostValue::cents($request, 'baseSalary');
    }

    /** @return array<string, mixed> */
    private function view(Dossier $dossier, EmployeeDraft $draft, ?Employee $employee, ?EmploymentTerms $shown, PayrollEstablishmentRepository $establishments): array
    {
        $today = $dossier->getDossierDate();

        return [
            'dossier' => $dossier,
            'draft' => $draft,
            'employee' => $employee,
            'shown_terms' => $shown,
            'current_terms' => $employee?->termsAt($today),
            'history' => null === $employee ? [] : EmploymentHistory::of($employee, $today),
            'collective_hours' => $establishments->findOfDossier($dossier)?->getMonthlyHours(),
            'sex_options' => array_map(static fn (Sex $s): array => ['value' => $s->value, 'label' => $s->label()], Sex::cases()),
            'contract_options' => array_map(static fn (ContractType $c): array => ['value' => $c->value, 'label' => $c->longLabel()], ContractType::cases()),
            'time_options' => array_map(static fn (WorkingTime $t): array => ['value' => $t->value, 'label' => $t->label()], WorkingTime::cases()),
            'status_options' => [['value' => 'non_executive', 'label' => 'Non-cadre'], ['value' => 'executive', 'label' => 'Cadre']],
        ];
    }
}
