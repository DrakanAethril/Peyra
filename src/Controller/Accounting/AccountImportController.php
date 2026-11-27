<?php

declare(strict_types=1);

namespace App\Controller\Accounting;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Security\Voter\DossierVoter;
use App\Service\Accounting\Chart\ChartImport;
use App\Service\Accounting\Chart\ChartImportReport;
use App\Service\Shared\Storage\UploadPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Plan de comptes › Importer (design-ux.md § 3.2, « Imports »): a CSV file read whole by
 * ChartImport. A file with one refused line writes nothing and comes back with every line named; an
 * accepted one is written at once and its report shown. The form is posted outside Turbo, whose
 * rule is to follow a redirect: the report is the answer to the post.
 */
final class AccountImportController extends AbstractController
{
    #[Route('/d/{dossier}/accounting/accounts/import', name: 'app_accounting_accounts_import', requirements: ['dossier' => '\d+'], methods: ['GET', 'POST'])]
    public function import(Dossier $dossier, Request $request, ChartImport $import, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        if (!$this->isGranted(DossierVoter::EDIT, $dossier)) {
            throw new NotFoundHttpException('Not yours to change.');
        }
        $policy = self::policy();
        $report = null;
        $fileName = null;
        $updateExisting = false;
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('accounting-import-'.$dossier->getId(), $request->request->getString('_token'))) {
                throw new NotFoundHttpException('Not yours to change.');
            }
            $updateExisting = $request->request->getBoolean('update');
            $file = $request->files->get('file');
            if (!$file instanceof UploadedFile) {
                $report = ChartImportReport::refused('Choisissez le fichier CSV à importer.');
            } elseif (null !== ($refusal = $policy->refusalOf($file))) {
                $report = ChartImportReport::refused($refusal);
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

        return $this->render('accounting/account_import.html.twig', [
            'dossier' => $dossier,
            'report' => $report,
            'file_name' => $fileName,
            'update_existing' => $updateExisting,
            'accept' => $policy->accept(),
        ], new Response(status: $status));
    }

    public static function policy(): UploadPolicy
    {
        return UploadPolicy::platform()->restrictTo('csv', 'txt')->limitedTo(2 * 1024 * 1024);
    }
}
