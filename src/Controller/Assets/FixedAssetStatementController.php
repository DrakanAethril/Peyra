<?php

declare(strict_types=1);

namespace App\Controller\Assets;

use App\Entity\Dossier\Dossier;
use App\Security\Voter\DossierRightVoter;
use App\Service\Accounting\Edition\Editions;
use App\Service\Accounting\Entry\AssistanceLevels;
use App\Service\Assets\Statement\FixedAssetStatement;
use App\Service\Assets\Statement\FixedAssetStatements;
use App\Service\Shared\Assistance\Assistance;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Pdf\PdfRenderer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Immobilisations et clôture › État des immobilisations (step 3.4b, gabarit R): the state of fixed assets
 * and depreciation of the exercise of the dossier's date, drawn up on that date (FixedAssetStatements) -
 * gross values, depreciation and impairments at the opening, their movements, at the end, the net book
 * value -, on screen and as a PDF. Read with ASSETS_READ; at « Aucun filet » a student sees the gross
 * values alone, the depreciation being theirs to work out.
 */
#[Route('/d/{dossier}/assets/statement', requirements: ['dossier' => '\d+'])]
final class FixedAssetStatementController extends AbstractController
{
    #[Route('', name: 'app_assets_statement', methods: ['GET'])]
    public function index(Dossier $dossier, FixedAssetStatements $statements, AssistanceLevels $levels): Response
    {
        return $this->render('assets/statement.html.twig', $this->context($dossier, $statements, $levels));
    }

    #[Route('.pdf', name: 'app_assets_statement_pdf', methods: ['GET'])]
    public function printed(Dossier $dossier, FixedAssetStatements $statements, AssistanceLevels $levels, PdfRenderer $renderer): Response
    {
        $context = $this->context($dossier, $statements, $levels);
        $statement = $context['statement'];
        if (null === $statement) {
            throw new NotFoundHttpException('No exercise holds the dossier’s date.');
        }
        $file = (new AsciiSlugger('fr'))->slug('etat des immobilisations '.$dossier->getName().' '.$dossier->getDossierDate()->format('Y-m-d'))->lower()->toString().'.pdf';

        return new Response($renderer->renderTemplate('assets/statement.pdf.html.twig', [...$context, 'pdf' => true, 'orientation' => 'landscape', 'report_header' => [
            'company' => $dossier->getCompany()->getName(),
            'title' => 'État des immobilisations et des amortissements',
            'fiscalYear' => 'Exercice '.$statement->exercise->getCode(),
            'period' => 'Du '.FrenchFormat::date($statement->exercise->getStartsOn()).' au '.FrenchFormat::date($statement->at),
            'dossierDate' => $dossier->getDossierDate(),
            'student' => Editions::student($dossier),
        ]]), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $file),
        ]);
    }

    /** @return array{dossier: Dossier, statement: ?FixedAssetStatement, calculates: bool} */
    private function context(Dossier $dossier, FixedAssetStatements $statements, AssistanceLevels $levels): array
    {
        if (!$this->isGranted(DossierRightVoter::ASSETS_READ, $dossier)) {
            throw new NotFoundHttpException('Not for this profile.');
        }

        return [
            'dossier' => $dossier,
            'statement' => $statements->at($dossier),
            'calculates' => Assistance::at($levels->for($dossier))->calculatesDepreciationPlan($this->isGranted('ROLE_TEACHER')),
        ];
    }
}
