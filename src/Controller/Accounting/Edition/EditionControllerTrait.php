<?php

declare(strict_types=1);

namespace App\Controller\Accounting\Edition;

use App\Entity\Dossier\Dossier;
use App\Service\Accounting\Edition\EditionWindow;
use App\Service\Accounting\Ledger\EntryScope;
use App\Service\Shared\Pdf\PdfRenderer;
use App\Service\Shared\QueryValue;
use App\Service\Shared\ReturnPath;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * What the five editions' controllers share (lot 1, step 1.5b): the scope chip, the parameters
 * kept from the screen to its PDF and its export, the PDF as an inline answer.
 */
trait EditionControllerTrait
{
    /** Brouillard included by default, as in the consultation; `scope=validated` for the validated entries only. */
    private static function scope(Request $request): EntryScope
    {
        return EntryScope::Validated->value === QueryValue::trimmed($request, 'scope') ? EntryScope::Validated : EntryScope::WithDrafts;
    }

    /**
     * The screen's parameters, as its PDF, its export and its chips carry them on.
     *
     * @param array<string, string|null> $more
     *
     * @return array<string, string|null>
     */
    private static function keep(EditionWindow $window, EntryScope $scope, array $more = []): array
    {
        return [
            'from' => $window->from->format('Y-m-d'),
            'to' => $window->to->format('Y-m-d'),
            'scope' => EntryScope::Validated === $scope ? 'validated' : null,
            ...$more,
        ];
    }

    /** The screen's own address, for the consultation's way back (`return`). */
    private static function here(Request $request): string
    {
        return ReturnPath::here($request->getPathInfo(), (string) $request->getQueryString());
    }

    /**
     * The state as a PDF, shown in the browser (« Aperçu PDF »), named after the state, the
     * dossier and its date: « balance-menuiserie-vasseur-sarl-2026-01-31.pdf ».
     *
     * @param array<string, mixed> $context
     */
    private static function pdf(PdfRenderer $renderer, string $template, array $context, string $name, Dossier $dossier): Response
    {
        $file = (new AsciiSlugger('fr'))->slug($name.' '.$dossier->getName().' '.$dossier->getDossierDate()->format('Y-m-d'))->lower()->toString().'.pdf';

        return new Response($renderer->renderTemplate($template, $context), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $file),
        ]);
    }
}
