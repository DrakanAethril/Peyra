<?php

declare(strict_types=1);

namespace App\Controller\Dossier;

use App\Entity\Dossier\Dossier;
use App\Enum\Audit\AuditActorRole;
use App\Repository\Audit\AuditEventRepository;
use App\Service\Audit\DossierTrailCriteria;
use App\Service\Audit\DossierTrailExport;
use App\Service\Shared\Export\ExportFormat;
use App\Service\Shared\Export\TableExport;
use App\Service\Shared\ListPage;
use App\Service\Shared\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Système d'information › Piste d'audit (design-ux.md § 3.2, gabarit L; conception § 4): every
 * gesture made in the dossier, newest first - who (a teacher's gesture signed with their name and
 * marked so, a member's with their profile), what, when in real time and at the dossier's date.
 * Read only, by whoever opens the dossier (DossierRouteListener has asked DossierVoter::VIEW);
 * filtered by author's role, gesture and words; exported whole.
 */
#[Route('/d/{dossier}/audit', requirements: ['dossier' => '\d+'])]
final class AuditTrailController extends AbstractController
{
    private const array FILTERS = ['role', 'action', 'q'];

    #[Route('', name: 'app_dossier_audit', methods: ['GET'])]
    public function index(Dossier $dossier, Request $request, AuditEventRepository $events): Response
    {
        $id = (int) $dossier->getId();
        $filters = $this->filters($request);
        $criteria = DossierTrailCriteria::read($filters);
        $total = $events->countDossier($id, $criteria);
        $page = ListPage::fromRequest($request, 'dossier.audit')->within($total);

        return $this->render('dossier/audit.html.twig', [
            'dossier' => $dossier,
            'criteria' => $criteria,
            'filters' => $filters,
            'events' => $events->findDossierPage($id, $criteria, $page->offset(), $page->perPage),
            'total' => $total,
            'role_counts' => [
                'all' => $events->countDossier($id, $criteria->withRole(null)),
                'teacher' => $events->countDossier($id, $criteria->withRole(AuditActorRole::Teacher)),
                'student' => $events->countDossier($id, $criteria->withRole(AuditActorRole::Student)),
            ],
            'actions' => $events->actionsOfDossier($id),
            'list_page' => $page,
        ]);
    }

    #[Route('/export.{format}', name: 'app_dossier_audit_export', requirements: ['format' => 'csv|xlsx'], methods: ['GET'])]
    public function export(Dossier $dossier, Request $request, string $format, AuditEventRepository $events): Response
    {
        $table = DossierTrailExport::table($events->findDossierAll((int) $dossier->getId(), DossierTrailCriteria::read($this->filters($request))));

        return TableExport::response($table, ExportFormat::from($format), 'piste-audit-'.$dossier->getName());
    }

    /** @return array<string, string> the filters as typed, empty ones left out */
    private function filters(Request $request): array
    {
        $filters = [];
        foreach (self::FILTERS as $name) {
            $value = QueryValue::trimmed($request, $name);
            if ('' !== $value) {
                $filters[$name] = $value;
            }
        }

        return $filters;
    }
}
