<?php

declare(strict_types=1);

namespace App\Controller\Payroll;

use App\Entity\Dossier\Dossier;
use App\Service\Shared\Pdf\PdfRenderer;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\String\Slugger\AsciiSlugger;

/** A payroll document as a PDF shown in the browser, named after the document, the dossier and its date. */
trait PayrollPdfTrait
{
    /** @param array<string, mixed> $context */
    private static function pdf(PdfRenderer $renderer, string $template, array $context, string $name, Dossier $dossier): Response
    {
        $file = (new AsciiSlugger('fr'))->slug($name.' '.$dossier->getName().' '.$dossier->getDossierDate()->format('Y-m-d'))->lower()->toString().'.pdf';

        return new Response($renderer->renderTemplate($template, $context), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $file),
        ]);
    }
}
