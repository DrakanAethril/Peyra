<?php

declare(strict_types=1);

namespace App\Controller\Payroll;

use App\Entity\Dossier\Dossier;
use App\Enum\Payroll\PayslipLine;
use App\Security\Voter\DossierRightVoter;
use App\Service\Accounting\Edition\Editions;
use App\Service\Payroll\Statement\PaidLeaveProvisions;
use App\Service\Payroll\Statement\PayrollMonthStatements;
use App\Service\Payroll\Statement\PayrollStatementsReader;
use App\Service\Regulatory\MissingRegulatoryValue;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Pdf\PdfRenderer;
use App\Service\Shared\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Paie et social › Éditions (step 4.5; design-ux.md § 3.2, gabarit R): the states of a month of pay,
 * read on its validated payslips - the journal de paie (by rubrique), the livre de paie (by employee),
 * the cotisations by body and the état des charges (by line of the clarified payslip) -, each with its
 * PDF; an amount leads to the détail of the payslips it sums, and each payslip to its PDF. Then the
 * provision for paid leave at the dossier's date. Read with PAYROLL_READ.
 */
#[Route('/d/{dossier}/payroll/editions', requirements: ['dossier' => '\d+'])]
final class PayrollEditionsController extends AbstractController
{
    use PayrollPdfTrait;

    /** The four states of a month, their route name's suffix and title. */
    private const array VIEWS = ['journal' => 'Journal de paie', 'book' => 'Livre de paie', 'bodies' => 'Cotisations par organisme', 'charges' => 'État des charges sociales'];

    #[Route('', name: 'app_payroll_edition_journal', defaults: ['view' => 'journal'], methods: ['GET'])]
    #[Route('/book', name: 'app_payroll_edition_book', defaults: ['view' => 'book'], methods: ['GET'])]
    #[Route('/bodies', name: 'app_payroll_edition_bodies', defaults: ['view' => 'bodies'], methods: ['GET'])]
    #[Route('/charges', name: 'app_payroll_edition_charges', defaults: ['view' => 'charges'], methods: ['GET'])]
    public function index(Dossier $dossier, string $view, Request $request, PayrollStatementsReader $reader): Response
    {
        return $this->render('payroll/edition/state.html.twig', $this->view($dossier, $view, $request, $reader));
    }

    #[Route('/{view}.pdf', name: 'app_payroll_edition_pdf', requirements: ['view' => 'journal|book|bodies|charges'], methods: ['GET'])]
    public function printed(Dossier $dossier, string $view, Request $request, PayrollStatementsReader $reader, PdfRenderer $renderer): Response
    {
        $context = $this->view($dossier, $view, $request, $reader);
        $statements = $context['statements'];
        if (!$statements instanceof PayrollMonthStatements || $statements->isEmpty()) {
            throw new NotFoundHttpException('No validated payslip that month.');
        }
        $company = $dossier->getCompany();

        return self::pdf($renderer, 'payroll/edition/pdf.html.twig', [...$context, 'pdf' => true, 'orientation' => \in_array($view, ['journal', 'book'], true) ? 'landscape' : 'portrait', 'report_header' => [
            'company' => $company->getName(),
            'title' => self::VIEWS[$view],
            'fiscalYear' => 'Paie '.$statements->month->format('Y'),
            'period' => ucfirst(FrenchFormat::period($statements->month)).' · bulletins validés',
            'dossierDate' => $dossier->getDossierDate(),
            'student' => Editions::student($dossier),
        ]], mb_strtolower(self::VIEWS[$view]).' '.$statements->month->format('Y-m'), $dossier);
    }

    /** The détail of an amount: the payslips of the month a rubrique (`item`), a body (`body`) or a line (`line`) sums. */
    #[Route('/detail', name: 'app_payroll_edition_detail', methods: ['GET'])]
    public function detail(Dossier $dossier, Request $request, PayrollStatementsReader $reader): Response
    {
        $context = $this->view($dossier, 'detail', $request, $reader);
        $statements = $context['statements'];
        if (!$statements instanceof PayrollMonthStatements) {
            throw new NotFoundHttpException('No validated payslip.');
        }
        $filter = [];
        $title = '';
        $item = QueryValue::trimmed($request, 'item');
        $line = PayslipLine::tryFrom(QueryValue::trimmed($request, 'line'));
        if (1 === preg_match('/^\d{4}$/', $item)) {
            $filter['item'] = $item;
            $title = 'Rubrique '.$item;
            foreach ($statements->allocations as $allocation) {
                if ($allocation->code === $item) {
                    $title .= ' · '.$allocation->label;
                    break;
                }
            }
        } elseif (null !== $line) {
            $filter['line'] = $line->value;
            $title = $line->label();
        } elseif ($request->query->has('body')) {
            $filter['body'] = QueryValue::trimmed($request, 'body');
            $title = 'Cotisations sans organisme';
            foreach ($statements->bodies() as $state) {
                if ($state['key'] === $filter['body']) {
                    $title = $state['body']?->getName() ?? $title;
                }
            }
        } else {
            throw new NotFoundHttpException('A détail needs a rubrique, a body or a line.');
        }

        return $this->render('payroll/edition/detail.html.twig', [...$context, 'detail_title' => $title, 'detail_rows' => $statements->detail($filter), 'back' => QueryValue::trimmed($request, 'from', 'journal')]);
    }

    #[Route('/provision', name: 'app_payroll_edition_provision', methods: ['GET'])]
    public function provision(Dossier $dossier, PaidLeaveProvisions $provisions): Response
    {
        $this->denyUnlessReads($dossier);
        try {
            $provision = $provisions->at($dossier, $dossier->getDossierDate());
            $missing = null;
        } catch (MissingRegulatoryValue $missingValue) {
            [$provision, $missing] = [null, $missingValue->getMessage()];
        }

        return $this->render('payroll/edition/provision.html.twig', ['dossier' => $dossier, 'edition_current' => 'provision', 'provision' => $provision, 'missing' => $missing, 'months' => [], 'statements' => null]);
    }

    /** @return array<string, mixed> the month shown (`?month=2026-03`, else the latest with a validated payslip), its states */
    private function view(Dossier $dossier, string $view, Request $request, PayrollStatementsReader $reader): array
    {
        $this->denyUnlessReads($dossier);
        $months = $reader->months($dossier);
        $asked = QueryValue::trimmed($request, 'month');
        $month = $months[0] ?? null;
        foreach ($months as $candidate) {
            if ($candidate->format('Y-m') === $asked) {
                $month = $candidate;
            }
        }

        return [
            'dossier' => $dossier,
            'edition_current' => $view,
            'months' => $months,
            'month' => $month,
            'statements' => null === $month ? null : $reader->of($dossier, $month),
        ];
    }

    private function denyUnlessReads(Dossier $dossier): void
    {
        if (!$this->isGranted(DossierRightVoter::PAYROLL_READ, $dossier)) {
            throw new NotFoundHttpException('The payroll is closed to this profile.');
        }
    }
}
