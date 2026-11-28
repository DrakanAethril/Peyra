<?php

declare(strict_types=1);

namespace App\Controller\ThirdParty;

use App\Entity\Dossier\Dossier;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Security\Voter\DossierRightVoter;
use App\Service\Shared\QueryValue;
use App\Service\Shared\ReturnPath;
use App\Service\ThirdParty\Schedule\DueSchedule;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The échéancier (step 2.7; design-ux.md § 3.2, gabarit R): Ventes › Suivi for the customers, Achats ›
 * Règlements for the suppliers - every open line of their accounts at the dossier's date, earliest due
 * first, what is overdue and by how many days; « Échues » narrows it. The aged balance of the same
 * kind is a link away. Read by whoever reads the module.
 */
#[Route('/d/{dossier}/{side}/schedule', requirements: ['dossier' => '\d+', 'side' => 'sales|purchases'])]
final class DueScheduleController extends AbstractController
{
    #[Route('', name: 'app_due_schedule', methods: ['GET'])]
    public function index(Dossier $dossier, string $side, Request $request, DueSchedule $schedule): Response
    {
        $sales = 'sales' === $side;
        if (!$this->isGranted($sales ? DossierRightVoter::SALES_READ : DossierRightVoter::PURCHASES_READ, $dossier)) {
            throw new NotFoundHttpException('Not a module this profile reads.');
        }
        $report = $schedule->read($dossier, $sales ? ThirdPartyKind::Customer : ThirdPartyKind::Supplier, $dossier->getDossierDate());
        $overdue = QueryValue::bool($request, 'overdue');

        return $this->render('third_party/schedule.html.twig', [
            'dossier' => $dossier,
            'side' => $side,
            'report' => $report,
            'overdue_only' => $overdue,
            'schedule_items' => $overdue ? $report->overdueItems() : $report->items,
            'here' => ReturnPath::here($request->getPathInfo(), (string) $request->getQueryString()),
        ]);
    }
}
