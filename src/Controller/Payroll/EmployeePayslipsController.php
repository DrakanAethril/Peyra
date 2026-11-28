<?php

declare(strict_types=1);

namespace App\Controller\Payroll;

use App\Entity\Dossier\Dossier;
use App\Entity\Payroll\Employee;
use App\Entity\Payroll\MonthlyPayslip;
use App\Repository\Payroll\EmployeeRepository;
use App\Repository\Payroll\MonthlyPayslipRepository;
use App\Security\Voter\DossierRightVoter;
use App\Service\Payroll\Engine\Payslip;
use App\Service\Payroll\Payslip\EmployeeAbsences;
use App\Service\Payroll\Payslip\PayslipBook;
use App\Service\Payroll\Payslip\PayslipElements;
use App\Service\Shared\Pdf\PdfRenderer;
use App\Service\Shared\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The fiche's payroll tabs (lot 4, step 4.4c; design-ux.md § 3.2 « Salariés »): « Bulletins », every
 * payslip of the employee with where it stands and, validated, its PDF whatever its month; « Absences »,
 * month by month, read on the elements entered; « Cumuls », the year's validated payslips and the year
 * to date after the last of them. Read only, with PAYROLL_READ: a payslip is changed on Bulletins.
 */
#[Route('/d/{dossier}/payroll/employees/{number}', requirements: ['dossier' => '\d+', 'number' => '[A-Z0-9]{1,10}'])]
final class EmployeePayslipsController extends AbstractController
{
    use PayrollPdfTrait;

    #[Route('/payslips', name: 'app_payroll_employee_payslips', methods: ['GET'])]
    public function payslips(Dossier $dossier, string $number, EmployeeRepository $employees, MonthlyPayslipRepository $payslips, PayslipBook $book): Response
    {
        $employee = $this->employee($dossier, $number, $employees);
        $rows = [];
        foreach (array_reverse($payslips->findOfEmployee($employee)) as $payslip) {
            $rows[] = ['payslip' => $payslip, 'result' => $payslip->isValidated() ? $book->resultOf($payslip)['payslip'] : null];
        }

        return $this->render('payroll/employee_payslips.html.twig', [
            'dossier' => $dossier,
            'employee' => $employee,
            'payslip_rows' => $rows,
            'month_paid' => $book->month($dossier),
        ]);
    }

    #[Route('/absences', name: 'app_payroll_employee_absences', methods: ['GET'])]
    public function absences(Dossier $dossier, string $number, Request $request, EmployeeRepository $employees, MonthlyPayslipRepository $payslips, PayslipBook $book): Response
    {
        $employee = $this->employee($dossier, $number, $employees);
        [$year, $years, $ofYear] = $this->year($request, $book, $dossier, $payslips->findOfEmployee($employee));
        $months = [];
        foreach ($ofYear as $payslip) {
            $months[$payslip->getMonth()->format('Y-m-d')] = PayslipElements::fromArray($payslip->getElements());
        }

        return $this->render('payroll/employee_absences.html.twig', [
            'dossier' => $dossier,
            'employee' => $employee,
            'year' => $year,
            'years' => $years,
            'absence_rows' => EmployeeAbsences::of($months, $book->plan($dossier)),
        ]);
    }

    #[Route('/year-to-date', name: 'app_payroll_employee_year_to_date', methods: ['GET'])]
    public function yearToDate(Dossier $dossier, string $number, Request $request, EmployeeRepository $employees, MonthlyPayslipRepository $payslips, PayslipBook $book): Response
    {
        $employee = $this->employee($dossier, $number, $employees);
        [$year, $years, $ofYear] = $this->year($request, $book, $dossier, $payslips->findOfEmployee($employee));
        $rows = [];
        $last = null;
        foreach ($ofYear as $payslip) {
            $result = $payslip->isValidated() ? $book->resultOf($payslip)['payslip'] : null;
            if ($result instanceof Payslip) {
                $rows[] = ['month' => $payslip->getMonth(), 'result' => $result];
                $last = $result;
            }
        }

        return $this->render('payroll/employee_year_to_date.html.twig', [
            'dossier' => $dossier,
            'employee' => $employee,
            'year' => $year,
            'years' => $years,
            'month_rows' => $rows,
            'year_to_date' => $last?->yearToDate,
        ]);
    }

    #[Route('/payslips/{month}.pdf', name: 'app_payroll_employee_payslip_pdf', requirements: ['month' => '\d{4}-\d{2}'], methods: ['GET'])]
    public function printed(Dossier $dossier, string $number, string $month, EmployeeRepository $employees, PayslipBook $book, PdfRenderer $renderer): Response
    {
        $employee = $this->employee($dossier, $number, $employees);
        $first = \DateTimeImmutable::createFromFormat('!Y-m-d', $month.'-01') ?: throw new NotFoundHttpException('No such month.');
        $payslip = $book->payslipOf($employee, $first);
        $result = true === $payslip?->isValidated() ? $book->resultOf($payslip)['payslip'] : null;
        if (null === $payslip || null === $result) {
            throw new NotFoundHttpException('Only a validated payslip is printed.');
        }

        return self::payslipPdf($renderer, $payslip, $result);
    }

    private function employee(Dossier $dossier, string $number, EmployeeRepository $employees): Employee
    {
        if (!$this->isGranted(DossierRightVoter::PAYROLL_READ, $dossier)) {
            throw new NotFoundHttpException('The payroll is closed to this profile.');
        }

        return $employees->findOneByNumber($dossier, $number) ?? throw new NotFoundHttpException('No such employee.');
    }

    /**
     * The calendar year shown (`?year=`, else the month paid's), the years the employee has payslips in, and that year's payslips.
     *
     * @param list<MonthlyPayslip> $all
     *
     * @return array{0: int, 1: list<int>, 2: list<MonthlyPayslip>}
     */
    private function year(Request $request, PayslipBook $book, Dossier $dossier, array $all): array
    {
        $current = (int) $book->month($dossier)->format('Y');
        $years = array_values(array_unique(array_merge([$current], array_map(static fn (MonthlyPayslip $p): int => (int) $p->getMonth()->format('Y'), $all))));
        rsort($years);
        $asked = QueryValue::nullableInt($request, 'year');
        $year = null !== $asked && \in_array($asked, $years, true) ? $asked : $current;

        return [$year, $years, array_values(array_filter($all, static fn (MonthlyPayslip $p): bool => (int) $p->getMonth()->format('Y') === $year))];
    }
}
