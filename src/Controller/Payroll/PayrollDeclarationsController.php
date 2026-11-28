<?php

declare(strict_types=1);

namespace App\Controller\Payroll;

use App\Entity\Dossier\Dossier;
use App\Security\Voter\DossierRightVoter;
use App\Service\Payroll\Declaration\DsnStatements;
use App\Service\Payroll\Declaration\SocialSchedule;
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
 * Paie et social › Déclarations (step 4.6; design-ux.md § 3.2, lot 4 § 4.6 reduced on 2026-11-28): the
 * preparatory state of a month's DSN - read only, never deposited - in its blocks, each amount leading to
 * the validated payslip it comes from, with its consistency checks and its PDF; then the échéancier
 * social, what the months leave to pay to the bodies and the tax office and where each payment stands at
 * the dossier's date (paid from Écritures de paie). Read with PAYROLL_READ.
 */
#[Route('/d/{dossier}/payroll/declarations', requirements: ['dossier' => '\d+'])]
final class PayrollDeclarationsController extends AbstractController
{
    use PayrollPdfTrait;

    #[Route('', name: 'app_payroll_declarations', methods: ['GET'])]
    public function dsn(Dossier $dossier, Request $request, PayrollStatementsReader $reader, DsnStatements $dsn): Response
    {
        return $this->render('payroll/declaration/dsn.html.twig', $this->read($dossier, $request, $reader, $dsn));
    }

    #[Route('/dsn.pdf', name: 'app_payroll_declarations_pdf', methods: ['GET'])]
    public function printed(Dossier $dossier, Request $request, PayrollStatementsReader $reader, DsnStatements $dsn, PdfRenderer $renderer): Response
    {
        $context = $this->read($dossier, $request, $reader, $dsn);
        $month = $context['month'];
        if (!$month instanceof \DateTimeImmutable || null === $context['preparation']) {
            throw new NotFoundHttpException('No preparatory state that month.');
        }

        return self::pdf($renderer, 'payroll/declaration/dsn.pdf.html.twig', [...$context, 'pdf' => true, 'orientation' => 'landscape', 'report_header' => [
            'company' => $dossier->getCompany()->getName(),
            'title' => 'État préparatoire de la DSN',
            'fiscalYear' => 'Paie '.FrenchFormat::ofPeriod($month),
            'dossierDate' => $dossier->getDossierDate(),
        ]], 'etat preparatoire dsn '.$month->format('Y-m'), $dossier);
    }

    #[Route('/schedule', name: 'app_payroll_declarations_schedule', methods: ['GET'])]
    public function schedule(Dossier $dossier, SocialSchedule $schedule): Response
    {
        $this->denyUnlessReads($dossier);
        try {
            [$dues, $missing] = [$schedule->of($dossier), null];
        } catch (MissingRegulatoryValue $missingValue) {
            [$dues, $missing] = [[], $missingValue->getMessage()];
        }

        return $this->render('payroll/declaration/schedule.html.twig', ['dossier' => $dossier, 'declaration_current' => 'schedule', 'dues' => $dues, 'missing' => $missing]);
    }

    /** @return array<string, mixed> the month shown (`?month=2026-03`, else the latest with a validated payslip) and its preparatory state */
    private function read(Dossier $dossier, Request $request, PayrollStatementsReader $reader, DsnStatements $dsn): array
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
        [$preparation, $dueOn, $missing] = [null, null, null];
        if (null !== $month) {
            try {
                [$preparation, $dueOn] = [$dsn->of($dossier, $month), $dsn->dueOn($dossier, $month)];
            } catch (MissingRegulatoryValue $missingValue) {
                $missing = $missingValue->getMessage();
            }
        }

        return [
            'dossier' => $dossier,
            'declaration_current' => 'dsn',
            'months' => $months,
            'month' => $month,
            'preparation' => $preparation,
            'due_on' => $dueOn,
            'missing' => $missing,
            'company' => $dossier->getCompany(),
            'establishment' => $dsn->establishment($dossier),
        ];
    }

    private function denyUnlessReads(Dossier $dossier): void
    {
        if (!$this->isGranted(DossierRightVoter::PAYROLL_READ, $dossier)) {
            throw new NotFoundHttpException('The payroll is closed to this profile.');
        }
    }
}
