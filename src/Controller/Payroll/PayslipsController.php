<?php

declare(strict_types=1);

namespace App\Controller\Payroll;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\Payroll\Employee;
use App\Entity\Payroll\MonthlyPayslip;
use App\Enum\Dossier\DossierKind;
use App\Enum\Payroll\PayslipStatus;
use App\Security\Voter\DossierRightVoter;
use App\Security\Voter\DossierVoter;
use App\Service\Accounting\Entry\AssistanceLevels;
use App\Service\Payroll\Engine\PayslipAdjustments;
use App\Service\Payroll\Engine\PayslipRow;
use App\Service\Payroll\Payslip\PayslipBook;
use App\Service\Payroll\Payslip\PayslipElementField;
use App\Service\Payroll\Payslip\PayslipElementFields;
use App\Service\Payroll\Payslip\PayslipElements;
use App\Service\Payroll\Payslip\PayslipElementsRules;
use App\Service\Payroll\Payslip\PayslipLineCells;
use App\Service\Payroll\Payslip\PayslipView;
use App\Service\Payroll\Plan\PayrollItemSpec;
use App\Service\Payroll\Statement\PayrollPosting;
use App\Service\Payroll\Statement\PayrollStatementsReader;
use App\Service\Shared\Assistance\Assistance;
use App\Service\Shared\FrenchFormat;
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
 * Paie et social › Bulletins (steps 4.4a and 4.4b), à la manière de Sage: the month paid with one
 * payslip per employee and where it stands (gabarit L), « Clore le mois » once every one is validated
 * and, for a teacher, « Rouvrir » the last month closed; then the payslip opened one employee at a time
 * (gabarit X, maquette 4): the employees of the month on the left, the « Éléments du mois » card, the
 * lines - a grid whose base and rate cells are typed in (a value forced is marked), Inser adds a
 * rubrique, Ctrl+Suppr removes one -, each line's explanation on a click or F5, and the figures under
 * them. Saving (F10, F9) calculates at once; « Valider le bulletin » (Ctrl+Entrée) freezes it; a
 * validated payslip is printed (« Aperçu PDF », a duplicata once sent), sent to the employee
 * (simulated) or cancelled while its month is open. Read with PAYROLL_READ, written with PAYROLL_ENTER;
 * reopening a month is a teacher's (DossierVoter::SET_DATE).
 */
#[Route('/d/{dossier}/payroll/payslips', requirements: ['dossier' => '\d+'])]
final class PayslipsController extends AbstractController
{
    use PayrollPdfTrait;

    #[Route('', name: 'app_payroll_payslips', methods: ['GET'])]
    public function index(Dossier $dossier, PayslipBook $book): Response
    {
        $this->readable($dossier);
        $month = $book->month($dossier);
        $employees = $book->employeesOf($dossier, $month);
        $payslips = $book->payslipsOf($dossier, $month);
        $statuses = $this->statuses($employees, $payslips);

        return $this->render('payroll/payslips.html.twig', [
            'dossier' => $dossier,
            'month' => $month,
            'employee_rows' => $employees,
            'statuses' => $statuses,
            'first_to_do' => $this->firstToDo($employees, $statuses),
            'closing_refusals' => $book->closingRefusals($dossier),
            'last_closed' => $book->lastClosed($dossier),
            'can_reopen' => $this->isGranted(DossierVoter::SET_DATE, $dossier) && $this->isGranted(DossierRightVoter::PAYROLL_ENTER, $dossier),
        ]);
    }

    #[Route('/close', name: 'app_payroll_payslips_close', methods: ['POST'])]
    public function close(Dossier $dossier, Request $request, PayslipBook $book, PayrollPosting $posting, PayrollStatementsReader $statements, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $this->mayEnter($dossier, $request, 'payroll-close-'.$dossier->getId());
        $month = $book->month($dossier);
        $refusal = $book->close($dossier, $me);
        if (null === $refusal) {
            $entityManager->flush();
            $posting->monthClosed($dossier, $statements->of($dossier, $month), $me);
            $this->addFlash('status', \sprintf('Paie %s close : %s s’ouvre.', FrenchFormat::ofPeriod($month), FrenchFormat::period($book->month($dossier))));
        } else {
            $this->addFlash('payslip_fault', $refusal);
        }

        return $this->redirectToRoute('app_payroll_payslips', ['dossier' => $dossier->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/reopen', name: 'app_payroll_payslips_reopen', methods: ['POST'])]
    public function reopen(Dossier $dossier, Request $request, PayslipBook $book, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        if (!$this->isGranted(DossierVoter::SET_DATE, $dossier)) {
            throw new NotFoundHttpException('Reopening a month of pay is a teacher’s.');
        }
        $this->mayEnter($dossier, $request, 'payroll-reopen-'.$dossier->getId());
        $last = $book->lastClosed($dossier);
        $refusal = $book->reopen($dossier, $me);
        if (null === $refusal) {
            $entityManager->flush();
            $this->addFlash('status', \sprintf('Paie %s rouverte.', FrenchFormat::ofPeriod($last?->getMonth() ?? $book->month($dossier))));
        } else {
            $this->addFlash('payslip_fault', $refusal);
        }

        return $this->redirectToRoute('app_payroll_payslips', ['dossier' => $dossier->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{number}', name: 'app_payroll_payslip', requirements: ['number' => '[A-Z0-9]{1,10}'], methods: ['GET', 'POST'])]
    public function payslip(Dossier $dossier, string $number, Request $request, PayslipBook $book, AssistanceLevels $levels, EntityManagerInterface $entityManager): Response
    {
        $this->readable($dossier);
        $assistance = self::assistance($dossier, $levels);
        $month = $book->month($dossier);
        [$employees, $index] = $this->place($book, $dossier, $month, $number);
        $employee = $employees[$index];
        $plan = $book->plan($dossier);
        $last = PayslipBook::lastEntries($employee, $month, $plan);
        $fields = PayslipElementFields::of($plan, $last);
        $takesPaidLeave = PayslipElementFields::takesPaidLeave($plan);
        $payslip = $book->payslipOf($employee, $month);
        $elements = PayslipElements::fromArray($payslip?->getElements() ?? []);
        $adjustments = PayslipAdjustments::fromArray($payslip?->getAdjustments() ?? []);
        $current = null === $payslip ? ['payslip' => null, 'refusal' => null] : $book->resultOf($payslip);
        $typed = $this->typed($fields, $elements);
        $cells = [];
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isGranted(DossierRightVoter::PAYROLL_ENTER, $dossier) || !$this->isCsrfTokenValid('payslip-'.$employee->getId(), $request->request->getString('_token')) || true === $payslip?->isValidated()) {
                throw new NotFoundHttpException('Not yours to change.');
            }
            $typed = $this->posted($request, $fields);
            $cells = PostValue::stringsByKey($request, 'cell');
            $read = PayslipElementsRules::read($fields, $takesPaidLeave, $typed, $last);
            $errors = $read['errors'];
            $lines = PayslipLineCells::read($current['payslip']->rows ?? [], $plan, $adjustments, $cells, PostValue::stringsByKey($request, 'shown'));
            $errors += $lines['errors'];
            $adjustments = $this->addedOrRemoved($request, $plan, $lines['adjustments'], $errors, true === $assistance?->startsPayslipEmpty());
            if (null !== $read['elements'] && [] === $errors) {
                $calculation = $book->calculate($employee, $month, $read['elements'], $plan, $adjustments);
                if (null === $calculation['refusal']) {
                    if (null === $payslip && null !== $calculation['payslip'] && true === $assistance?->startsPayslipEmpty()) {
                        // Level 0: the payslip starts with no line, the student adds each rubrique (Inser).
                        foreach ($calculation['payslip']->rows as $row) {
                            $adjustments = $adjustments->withRemoved($row->code);
                        }
                    }
                    $book->record($employee, $month, $read['elements'], $adjustments);
                    $entityManager->flush();
                    $this->addFlash('status', \sprintf('Bulletin de %s calculé.', $employee->getFullName()));

                    return $this->redirectToRoute('app_payroll_payslip', ['dossier' => $dossier->getId(), 'number' => $number], Response::HTTP_SEE_OTHER);
                }
                $errors['calculation'] = $calculation['refusal'];
            }
        }

        $status = $payslip?->getStatus() ?? PayslipStatus::ToDo;
        $payslips = $book->payslipsOf($dossier, $month);
        $specs = [];
        foreach ($plan as $spec) {
            $specs[$spec->code] = $spec;
        }
        $shown = null === $current['payslip'] ? [] : array_map(static fn (PayslipRow $row): string => $row->code, $current['payslip']->rows);
        // The cells stay those of the payslip as it stood when the post is refused: what was typed is shown in them.
        $grid = null === $current['payslip'] || true === $payslip?->isValidated() || !$this->isGranted(DossierRightVoter::PAYROLL_ENTER, $dossier) ? [] : $current['payslip']->rows;
        [$open, $texts] = $this->openCells($grid, $specs, false === $assistance?->calculatesPayslip());
        $check = null === $current['payslip'] || true === $payslip?->isValidated() ? null : $book->entryCheck($employee, $month, $elements, PayslipAdjustments::fromArray($payslip?->getAdjustments() ?? []), $current['payslip'], $plan, $assistance);
        // The « Contrôles » card of a calculated payslip (G, B, A, and a template, which the teacher checks too).
        $controls = null === $check || $check->typedByStudent || false === $assistance?->controlsPayslip() ? null : $book->controls($employee, $month, $elements, $current['payslip'], $check);

        return $this->render('payroll/payslip.html.twig', [
            'dossier' => $dossier,
            'month' => $month,
            'pay_day' => PayslipBook::payDay($month),
            'employee' => $employee,
            'terms' => $employee->termsAt(PayslipBook::payDay($month)),
            'status' => $status,
            'payslip' => $payslip,
            'employee_rows' => $employees,
            'statuses' => $this->statuses($employees, $payslips),
            'previous' => $employees[$index - 1] ?? null,
            'next' => $employees[$index + 1] ?? null,
            'fields' => $fields,
            'takes_paid_leave' => $takesPaidLeave,
            'last_entries' => $last,
            'typed' => $typed,
            'cells' => $cells,
            'errors' => $errors,
            'has_establishment' => $book->hasEstablishment($dossier),
            'result' => $current['payslip'],
            'refusal' => $current['refusal'],
            'payslip_view' => null === $current['payslip'] ? null : PayslipView::of($current['payslip']),
            'open_cells' => $open,
            'entry_check' => $check,
            'controls' => $controls,
            'assistance' => $assistance,
            'cell_texts' => $texts,
            'addable' => null === $current['payslip'] ? [] : array_values(array_filter($plan, static fn (PayrollItemSpec $spec): bool => !\in_array($spec->code, $shown, true))),
        ], new Response(null, [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/{number}/validate', name: 'app_payroll_payslip_validate', requirements: ['number' => '[A-Z0-9]{1,10}'], methods: ['POST'])]
    public function validate(Dossier $dossier, string $number, Request $request, PayslipBook $book, AssistanceLevels $levels, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $payslip = $this->stored($dossier, $number, $request, $book, 'payslip-validate-');
        $refusal = $book->validate($payslip, $me, self::assistance($dossier, $levels));
        if (null !== $refusal) {
            $this->addFlash('payslip_fault', $refusal);

            return $this->redirectToRoute('app_payroll_payslip', ['dossier' => $dossier->getId(), 'number' => $number], Response::HTTP_SEE_OTHER);
        }
        $entityManager->flush();
        $this->addFlash('status', \sprintf('Bulletin de %s validé.', $payslip->getEmployee()->getFullName()));
        // The next one « À faire » opens (design-ux.md § 4.7), else the month's list.
        $month = $book->month($dossier);
        $employees = $book->employeesOf($dossier, $month);
        $next = $this->firstToDo($employees, $this->statuses($employees, $book->payslipsOf($dossier, $month)), strict: true);

        return null === $next
            ? $this->redirectToRoute('app_payroll_payslips', ['dossier' => $dossier->getId()], Response::HTTP_SEE_OTHER)
            : $this->redirectToRoute('app_payroll_payslip', ['dossier' => $dossier->getId(), 'number' => $next->getRegistrationNumber()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{number}/cancel', name: 'app_payroll_payslip_cancel', requirements: ['number' => '[A-Z0-9]{1,10}'], methods: ['POST'])]
    public function cancel(Dossier $dossier, string $number, Request $request, PayslipBook $book, PayrollPosting $posting, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $payslip = $this->stored($dossier, $number, $request, $book, 'payslip-cancel-');
        // The month's entry, if the payroll wrote one, goes first: deleted or reversed (step 4.5); refused, the payslip stays validated.
        $refusal = $payslip->isValidated() && $payslip->getMonth() == $book->month($dossier) ? $posting->withdraw($dossier, $payslip->getMonth(), $me) : null;
        $refusal ??= $book->cancel($payslip, $me);
        if (null === $refusal) {
            $entityManager->flush();
            $this->addFlash('status', \sprintf('Validation du bulletin de %s annulée : il se modifie et se valide de nouveau.', $payslip->getEmployee()->getFullName()));
        } else {
            $this->addFlash('payslip_fault', $refusal);
        }

        return $this->redirectToRoute('app_payroll_payslip', ['dossier' => $dossier->getId(), 'number' => $number], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{number}/send', name: 'app_payroll_payslip_send', requirements: ['number' => '[A-Z0-9]{1,10}'], methods: ['POST'])]
    public function send(Dossier $dossier, string $number, Request $request, PayslipBook $book, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $payslip = $this->stored($dossier, $number, $request, $book, 'payslip-send-');
        if (!$payslip->isValidated()) {
            throw new NotFoundHttpException('Only a validated payslip is sent.');
        }
        $book->send($payslip, $me);
        $entityManager->flush();
        $this->addFlash('status', \sprintf('Bulletin envoyé à %s (envoi simulé : rien ne quitte Peyra).', $payslip->getEmployee()->getFullName()));

        return $this->redirectToRoute('app_payroll_payslip', ['dossier' => $dossier->getId(), 'number' => $number], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{number}/payslip.pdf', name: 'app_payroll_payslip_pdf', requirements: ['number' => '[A-Z0-9]{1,10}'], methods: ['GET'])]
    public function printed(Dossier $dossier, string $number, PayslipBook $book, PdfRenderer $renderer): Response
    {
        $this->readable($dossier);
        $month = $book->month($dossier);
        [$employees, $index] = $this->place($book, $dossier, $month, $number);
        $employee = $employees[$index];
        $payslip = $book->payslipOf($employee, $month);
        if (null === $payslip || !$payslip->isValidated()) {
            throw new NotFoundHttpException('Only a validated payslip is printed.');
        }
        $result = $book->resultOf($payslip)['payslip'] ?? throw new NotFoundHttpException('A frozen payslip reads.');

        return self::payslipPdf($renderer, $payslip, $result);
    }

    /**
     * The level the payslip is worked at (step 4.4c, review 4.7); none in a template, which the teacher
     * prepares: its payslips are calculated by the tool, their controls never stop it.
     */
    private static function assistance(Dossier $dossier, AssistanceLevels $levels): ?Assistance
    {
        return DossierKind::Template === $dossier->getKind() ? null : Assistance::at($levels->for($dossier));
    }

    private function readable(Dossier $dossier): void
    {
        if (!$this->isGranted(DossierRightVoter::PAYROLL_READ, $dossier)) {
            throw new NotFoundHttpException('The payroll is closed to this profile.');
        }
    }

    private function mayEnter(Dossier $dossier, Request $request, string $tokenId): void
    {
        if (!$this->isGranted(DossierRightVoter::PAYROLL_ENTER, $dossier) || !$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not yours to change.');
        }
    }

    /** @return array{0: list<Employee>, 1: int} the employees of the month and the place of that one among them */
    private function place(PayslipBook $book, Dossier $dossier, \DateTimeImmutable $month, string $number): array
    {
        $employees = $book->employeesOf($dossier, $month);
        foreach ($employees as $i => $candidate) {
            if ($candidate->getRegistrationNumber() === $number) {
                return [$employees, $i];
            }
        }

        throw new NotFoundHttpException('No payslip of that employee this month.');
    }

    /** The payslip of the month of that employee, for a gesture on it (validate, cancel, send). */
    private function stored(Dossier $dossier, string $number, Request $request, PayslipBook $book, string $tokenPrefix): MonthlyPayslip
    {
        $this->readable($dossier);
        $month = $book->month($dossier);
        [$employees, $index] = $this->place($book, $dossier, $month, $number);
        $employee = $employees[$index];
        $this->mayEnter($dossier, $request, $tokenPrefix.$employee->getId());

        return $book->payslipOf($employee, $month) ?? throw new NotFoundHttpException('No payslip calculated yet.');
    }

    /**
     * A rubrique added (Inser, the list of the plan) or removed (Ctrl+Suppr) by the button that posted;
     * a due rubrique is removed only where the student establishes the payslip from nothing (level 0).
     *
     * @param list<PayrollItemSpec>  $plan
     * @param array<string, string>  $errors
     */
    private function addedOrRemoved(Request $request, array $plan, PayslipAdjustments $adjustments, array &$errors, bool $anyRemovable): PayslipAdjustments
    {
        $add = PostValue::trimmed($request, 'add');
        $remove = PostValue::trimmed($request, 'remove');
        foreach ($plan as $spec) {
            if ($spec->code === $add) {
                return $adjustments->withAdded($add);
            }
            if ($spec->code === $remove) {
                if (!$anyRemovable && !PayslipLineCells::removable($spec)) {
                    $errors['remove'] = \sprintf('La rubrique %s · %s est due : elle ne se retire pas du bulletin.', $spec->code, $spec->label);

                    return $adjustments;
                }

                return $adjustments->withRemoved($remove);
            }
        }

        return $adjustments;
    }

    /**
     * @param list<PayslipRow>                  $rows
     * @param array<array-key, PayrollItemSpec>  $specs by code
     *
     * @return array{0: array<string, list<string>>, 1: array<string, string>} the open cells of each line by code, and what each shows by « code.field »
     */
    private function openCells(array $rows, array $specs, bool $typedOnly): array
    {
        $open = [];
        $texts = [];
        foreach ($rows as $row) {
            $open[$row->code] = PayslipLineCells::open($row, $specs[$row->code] ?? null);
            foreach ($open[$row->code] as $field) {
                // Where the student establishes the payslip (level 0) a cell shows what they typed, never the calculation.
                $texts[$row->code.'.'.$field] = $typedOnly && !$row->isForced($field) ? '' : PayslipLineCells::text($row, $field);
            }
        }

        return [$open, $texts];
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
     * The first payslip still to do, or calculated and not validated; with `strict`, none once every
     * one is validated, else the first of the month.
     *
     * @param list<Employee>            $employees
     * @param array<int, PayslipStatus> $statuses  by employee id
     */
    private function firstToDo(array $employees, array $statuses, bool $strict = false): ?Employee
    {
        foreach ([PayslipStatus::ToDo, PayslipStatus::Calculated, PayslipStatus::Modified] as $wanted) {
            foreach ($employees as $employee) {
                if ($wanted === ($statuses[(int) $employee->getId()] ?? PayslipStatus::ToDo)) {
                    return $employee;
                }
            }
        }

        return $strict ? null : ($employees[0] ?? null);
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
        foreach (['paidLeave' => $elements->paidLeave, 'compensatoryLeave' => $elements->compensatoryLeave] as $prefix => $leave) {
            $typed[$prefix.'.hours'] = null === $leave ? '' : PayslipElements::french($leave->hours);
            $typed[$prefix.'.daysTaken'] = null === $leave ? '' : PayslipElements::french($leave->daysTaken);
            $typed[$prefix.'.daysAcquired'] = null === $leave ? '' : PayslipElements::french($leave->daysAcquired);
            $typed[$prefix.'.referenceGross'] = null === $leave ? '' : PayslipElements::french($leave->referenceGross->toDecimal());
        }
        $severance = $elements->severance;
        $typed['severance.referenceSalary'] = null === $severance ? '' : PayslipElements::french($severance->referenceSalary->toDecimal());
        $typed['severance.agreed'] = null === $severance?->agreed ? '' : PayslipElements::french($severance->agreed->toDecimal());
        $typed['severance.previousYearGross'] = null === $severance?->previousYearGross ? '' : PayslipElements::french($severance->previousYearGross->toDecimal());
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
        foreach (['paidLeave', 'compensatoryLeave'] as $prefix) {
            $leave = PostValue::stringsByKey($request, $prefix);
            foreach (PayslipElementsRules::PAID_LEAVE as $part) {
                $typed[$prefix.'.'.$part] = $leave[$part] ?? '';
            }
        }
        $severance = PostValue::stringsByKey($request, 'severance');
        foreach (PayslipElementsRules::SEVERANCE as $part) {
            $typed['severance.'.$part] = $severance[$part] ?? '';
        }
        $typed['unpaidDays'] = PostValue::trimmed($request, 'unpaidDays');

        return $typed;
    }
}
