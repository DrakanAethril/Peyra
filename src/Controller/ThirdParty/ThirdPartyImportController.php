<?php

declare(strict_types=1);

namespace App\Controller\ThirdParty;

use App\Controller\Accounting\AccountImportController;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Security\Voter\DossierRightVoter;
use App\Service\ThirdParty\ThirdPartyImport;
use App\Service\ThirdParty\ThirdPartyImportReport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Tiers › Importer: a CSV file read whole by ThirdPartyImport, as the plan's import does. A file
 * with one refused line writes nothing and comes back with every line named; an accepted one is
 * written at once and its report shown. Posted outside Turbo: the report answers the post.
 */
final class ThirdPartyImportController extends AbstractController
{
    #[Route('/d/{dossier}/accounting/third-parties/import', name: 'app_third_parties_import', requirements: ['dossier' => '\d+'], methods: ['GET', 'POST'])]
    public function import(Dossier $dossier, Request $request, ThirdPartyImport $import, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        if (!$this->isGranted(DossierRightVoter::SETTINGS, $dossier)) {
            throw new NotFoundHttpException('Not yours to change.');
        }
        $policy = AccountImportController::policy();
        $report = null;
        $fileName = null;
        $updateExisting = false;
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('third-parties-import-'.$dossier->getId(), $request->request->getString('_token'))) {
                throw new NotFoundHttpException('Not yours to change.');
            }
            $updateExisting = $request->request->getBoolean('update');
            $file = $request->files->get('file');
            if (!$file instanceof UploadedFile) {
                $report = ThirdPartyImportReport::refused('Choisissez le fichier CSV à importer.');
            } elseif (null !== ($refusal = $policy->refusalOf($file))) {
                $report = ThirdPartyImportReport::refused($refusal);
            } else {
                $fileName = $file->getClientOriginalName();
                $report = $import->prepare($dossier, (string) file_get_contents($file->getPathname()), $updateExisting);
                if ($report->isApplicable()) {
                    $import->apply($dossier, $report, $me, $fileName);
                    $entityManager->flush();
                }
            }
            $status = $report->isApplicable() ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return $this->render('third_party/import.html.twig', [
            'dossier' => $dossier,
            'report' => $report,
            'file_name' => $fileName,
            'update_existing' => $updateExisting,
            'accept' => $policy->accept(),
        ], new Response(status: $status));
    }
}
