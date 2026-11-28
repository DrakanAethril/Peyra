<?php

declare(strict_types=1);

namespace App\Controller\Payroll;

use App\Entity\Dossier\Dossier;
use App\Entity\Payroll\Employee;
use App\Entity\Payroll\MonthlyPayslip;
use App\Enum\Payroll\PayslipStatus;
use App\Security\Voter\DossierRightVoter;
use App\Service\Payroll\Payslip\PayslipBook;
use App\Service\Payroll\Payslip\PayslipElementField;
use App\Service\Payroll\Payslip\PayslipElementFields;
use App\Service\Payroll\Payslip\PayslipElements;
use App\Service\Payroll\Payslip\PayslipElementsRules;
use App\Service\Payroll\Payslip\PayslipView;
use App\Service\Shared\PostValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Paie et social › Bulletins (step 4.4a), à la manière de Sage: the month paid with one payslip per
 * employee and where it stands (gabarit L), then the payslip opened one employee at a time (gabarit
 * X, maquette 4): the employees of the month on the left, the « Éléments du mois » card, the lines the
 * engine calculated - each one's explanation on a click or F5 - and the figures under them.
 * Ctrl ↑ / Ctrl ↓ go to the previous, next employee. Saving the elements (F10, F9) calculates at
 * once. Read with PAYROLL_READ, written with PAYROLL_ENTER.
 */
#[Route('/d/{dossier}/payroll/payslips', requirements: ['dossier' => '\d+'])]
final class PayslipsController extends AbstractController
{
    #[Route('', name: 'app_payroll_payslips', methods: ['GET'])]
    public function index(Dossier $dossier, PayslipBook $book): Response
    {
        $this->readable($dossier);
        $month = PayslipBook::month($dossier);
        $employees = $book->employeesOf($dossier, $month);
        $payslips = $book->payslipsOf($dossier, $month);

        return $this->render('payroll/payslips.html.twig', [
            'dossier' => $dossier,
            'month' => $month,
            'employee_rows' => $employees,
            'statuses' => $this->statuses($employees, $payslips),
            'first_to_do' => $this->firstToDo($employees, $payslips),
        ]);
    }

    #[Route('/{number}', name: 'app_payroll_payslip', requirements: ['number' => '[A-Z0-9]{1,10}'], methods: ['GET', 'POST'])]
    public function payslip(Dossier $dossier, string $number, Request $request, PayslipBook $book, EntityManagerInterface $entityManager): Response
    {
        $this->readable($dossier);
        $month = PayslipBook::month($dossier);
        $employees = $book->employeesOf($dossier, $month);
        $index = null;
        foreach ($employees as $i => $candidate) {
            if ($candidate->getRegistrationNumber() === $number) {
                $index = $i;
            }
        }
        if (null === $index) {
            throw new NotFoundHttpException('No payslip of that employee this month.');
        }
        $employee = $employees[$index];
        $plan = $book->plan($dossier);
        $fields = PayslipElementFields::of($plan);
        $takesPaidLeave = PayslipElementFields::takesPaidLeave($plan);
        $payslip = $book->payslipOf($employee, $month);
        $elements = PayslipElements::fromArray($payslip?->getElements() ?? []);
        $typed = $this->typed($fields, $elements);
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isGranted(DossierRightVoter::PAYROLL_ENTER, $dossier) || !$this->isCsrfTokenValid('payslip-'.$employee->getId(), $request->request->getString('_token'))) {
                throw new NotFoundHttpException('Not yours to change.');
            }
            $typed = $this->posted($request, $fields);
            $read = PayslipElementsRules::read($fields, $takesPaidLeave, $typed);
            $errors = $read['errors'];
            if (null !== $read['elements']) {
                $calculation = $book->calculate($employee, $month, $read['elements'], $plan);
                if (null === $calculation['refusal']) {
                    $book->record($employee, $month, $read['elements']);
                    $entityManager->flush();
                    $this->addFlash('status', \sprintf('Bulletin de %s calculé.', $employee->getFullName()));

                    return $this->redirectToRoute('app_payroll_payslip', ['dossier' => $dossier->getId(), 'number' => $number], Response::HTTP_SEE_OTHER);
                }
                $errors['calculation'] = $calculation['refusal'];
            }
        }

        $status = $payslip?->getStatus() ?? PayslipStatus::ToDo;
        $calculation = PayslipStatus::Calculated === $status && [] === $errors ? $book->calculate($employee, $month, $elements, $plan) : ['payslip' => null, 'refusal' => null];
        $payslips = $book->payslipsOf($dossier, $month);

        return $this->render('payroll/payslip.html.twig', [
            'dossier' => $dossier,
            'month' => $month,
            'pay_day' => PayslipBook::payDay($month),
            'employee' => $employee,
            'terms' => $employee->termsAt(PayslipBook::payDay($month)),
            'status' => $status,
            'employee_rows' => $employees,
            'statuses' => $this->statuses($employees, $payslips),
            'previous' => $employees[$index - 1] ?? null,
            'next' => $employees[$index + 1] ?? null,
            'fields' => $fields,
            'takes_paid_leave' => $takesPaidLeave,
            'typed' => $typed,
            'errors' => $errors,
            'has_establishment' => $book->hasEstablishment($dossier),
            'result' => $calculation['payslip'],
            'refusal' => $calculation['refusal'],
            'payslip_view' => null === $calculation['payslip'] ? null : PayslipView::of($calculation['payslip']),
        ], new Response(null, [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    private function readable(Dossier $dossier): void
    {
        if (!$this->isGranted(DossierRightVoter::PAYROLL_READ, $dossier)) {
            throw new NotFoundHttpException('The payroll is closed to this profile.');
        }
    }

    /**
     * @param list<Employee>              $employees
     * @param array<int, MonthlyPayslip>  $payslips
     *
     * @return array<int, PayslipStatus> by employee id
     */
    private function statuses(array $employees, array $payslips): array
    {
        $statuses = [];
        foreach ($employees as $employee) {
            $statuses[(int) $employee->getId()] = ($payslips[(int) $employee->getId()] ?? null)?->getStatus() ?? PayslipStatus::ToDo;
        }

        return $statuses;
    }

    /**
     * @param list<Employee>             $employees
     * @param array<int, MonthlyPayslip> $payslips
     */
    private function firstToDo(array $employees, array $payslips): ?Employee
    {
        foreach ($employees as $employee) {
            if (PayslipStatus::ToDo === (($payslips[(int) $employee->getId()] ?? null)?->getStatus() ?? PayslipStatus::ToDo)) {
                return $employee;
            }
        }

        return $employees[0] ?? null;
    }

    /**
     * @param list<PayslipElementField> $fields
     *
     * @return array<string, string> what each field shows, by key
     */
    private function typed(array $fields, PayslipElements $elements): array
    {
        $typed = [];
        foreach ($fields as $field) {
            $typed[$field->key()] = $elements->shown($field);
        }
        $leave = $elements->paidLeave;
        $typed['paidLeave.hours'] = null === $leave ? '' : PayslipElements::french($leave->hours);
        $typed['paidLeave.daysTaken'] = null === $leave ? '' : PayslipElements::french($leave->daysTaken);
        $typed['paidLeave.daysAcquired'] = null === $leave ? '' : PayslipElements::french($leave->daysAcquired);
        $typed['paidLeave.referenceGross'] = null === $leave ? '' : PayslipElements::french($leave->referenceGross->toDecimal());
        $typed['unpaidDays'] = 0 === $elements->unpaidDays ? '' : (string) $elements->unpaidDays;

        return $typed;
    }

    /**
     * @param list<PayslipElementField> $fields
     *
     * @return array<string, string>
     */
    private function posted(Request $request, array $fields): array
    {
        $byKind = [];
        foreach ([PayslipElementField::QUANTITY, PayslipElementField::AMOUNT, PayslipElementField::RATE] as $kind) {
            $byKind[$kind] = PostValue::stringsByKey($request, $kind);
        }
        $typed = [];
        foreach ($fields as $field) {
            $typed[$field->key()] = $byKind[$field->kind][$field->code] ?? '';
        }
        $leave = PostValue::stringsByKey($request, 'paidLeave');
        foreach (PayslipElementsRules::PAID_LEAVE as $part) {
            $typed['paidLeave.'.$part] = $leave[$part] ?? '';
        }
        $typed['unpaidDays'] = PostValue::trimmed($request, 'unpaidDays');

        return $typed;
    }
}
