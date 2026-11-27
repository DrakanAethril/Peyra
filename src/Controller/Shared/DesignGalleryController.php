<?php

declare(strict_types=1);

namespace App\Controller\Shared;

use App\Service\Shared\ListPage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The design system's gallery (design/validated/plan, step 0.3): every Px component in every state,
 * inside the two shells, plus the public shell. Development and test only - the routes do not
 * exist in production. bin/shot captures these pages to compare them with the mock-ups.
 */
#[Route('/_design', env: ['dev', 'test'])]
final class DesignGalleryController extends AbstractController
{
    #[Route('', name: 'app_design_gallery', methods: ['GET'])]
    public function gallery(Request $request): Response
    {
        return $this->render('design/gallery.html.twig', [
            'sort' => $request->query->getString('sort', 'date'),
            'direction' => 'desc' === $request->query->getString('dir') ? 'desc' : 'asc',
            'list_page' => ListPage::fromRequest($request, 'design.gallery')->within(431),
            // The demonstration dossier's date (step 1.1 gives each dossier its own).
            'gallery_reference' => new \DateTimeImmutable('2026-11-30'),
            'gallery_entry_date' => new \DateTimeImmutable('2026-11-12'),
            'gallery_clock' => new \DateTimeImmutable('2026-11-30 10:42'),
            'reason' => $request->query->getString('motif'),
        ]);
    }

    #[Route('/teacher', name: 'app_design_teacher', methods: ['GET'])]
    public function teacher(): Response
    {
        return $this->render('design/teacher.html.twig');
    }

    #[Route('/public', name: 'app_design_public', methods: ['GET'])]
    public function public(Request $request): Response
    {
        $code = 500 === self::number($request, 'code') ? 500 : 404;

        return $this->render('bundles/TwigBundle/Exception/error.html.twig', [
            'status_code' => $code,
            'status_text' => 404 === $code ? 'Not Found' : 'Internal Server Error',
        ]);
    }

    /**
     * A whole number read from the query string, null when absent, empty or not a number:
     * InputBag::getInt() answers a 400 to the empty string a form submits (CLAUDE.md).
     */
    private static function number(Request $request, string $key): ?int
    {
        $value = $request->query->getString($key);

        return ctype_digit($value) ? (int) $value : null;
    }

    /**
     * The dialog's fragment, served at opening and posted back by fetch: a refusal is the fragment
     * again with a 422, a success a redirect the page follows (design-ui.md § 4.14).
     */
    #[Route('/dialog', name: 'app_design_dialog', methods: ['GET', 'POST'])]
    public function dialog(Request $request): Response
    {
        $reason = trim($request->request->getString('motif'));
        $error = null;
        if ($request->isMethod('POST')) {
            if ('' !== $reason) {
                return $this->redirectToRoute('app_design_gallery', ['motif' => $reason], Response::HTTP_SEE_OTHER);
            }
            $error = 'Indiquez le motif de la contrepassation.';
        }

        $response = $this->render('design/_dialog_form.html.twig', ['reason' => $reason, 'error' => $error]);
        if (null !== $error) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $response;
    }
}
