<?php

declare(strict_types=1);

namespace App\Controller\Payroll;

use App\Entity\Dossier\Dossier;
use App\Entity\Payroll\Employee;
use App\Enum\Payroll\EmployeeState;
use App\Repository\Payroll\EmployeeRepository;
use App\Security\Voter\DossierRightVoter;
use App\Service\Shared\ListPage;
use App\Service\Shared\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Paie et social › Salariés (lot 4, step 4.2a; gabarit L): the dossier's employees by matricule,
 * with their job, status, contract, day of hire and base salary at the dossier's date; filtered by
 * where they stand that day (present, hire to come), searched by matricule or name. A line opens
 * the fiche (EmployeeController). Read with PAYROLL_READ.
 */
#[Route('/d/{dossier}/payroll/employees', requirements: ['dossier' => '\d+'])]
final class EmployeesController extends AbstractController
{
    #[Route('', name: 'app_payroll_employees', methods: ['GET'])]
    public function index(Dossier $dossier, Request $request, EmployeeRepository $employees): Response
    {
        if (!$this->isGranted(DossierRightVoter::PAYROLL_READ, $dossier)) {
            throw new NotFoundHttpException('The payroll is closed to this profile.');
        }
        $today = $dossier->getDossierDate();
        $all = $employees->findByDossier($dossier);
        $search = QueryValue::trimmed($request, 'q');
        $state = EmployeeState::tryFrom(QueryValue::trimmed($request, 'state'));

        $needle = mb_strtolower($search);
        $found = '' === $needle ? $all : array_values(array_filter($all, static fn (Employee $e): bool => str_contains(mb_strtolower($e->getRegistrationNumber().' '.$e->getSortName().' '.$e->getFullName()), $needle)));
        $counts = ['' => \count($found)];
        foreach (EmployeeState::cases() as $case) {
            $counts[$case->value] = 0;
        }
        foreach ($found as $employee) {
            ++$counts[$employee->stateAt($today)->value];
        }
        $shown = null === $state ? $found : array_values(array_filter($found, static fn (Employee $e): bool => $e->stateAt($today) === $state));
        $page = ListPage::fromRequest($request, 'employees')->within(\count($shown));

        return $this->render('payroll/employees.html.twig', [
            'dossier' => $dossier,
            'total' => \count($all),
            'employee_rows' => \array_slice($shown, $page->offset(), $page->perPage),
            'shown_total' => \count($shown),
            'list_page' => $page,
            'counts' => $counts,
            'states' => EmployeeState::cases(),
            'state' => $state,
            'search' => $search,
        ]);
    }
}
