<?php

declare(strict_types=1);

namespace App\Controller\Shared;

use App\Service\Shared\ListPage;
use App\Service\Shared\Money;
use App\Service\Shared\Pdf\PdfRenderer;
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
     * The base of the states Peyra edits (doc/_report.html.twig), on a sample balance: in the
     * browser as a preview, and as the PDF Gotenberg makes of it (design-ui.md § 3.6).
     */
    #[Route('/document', name: 'app_design_document', methods: ['GET'])]
    public function document(): Response
    {
        return $this->render('design/document.html.twig', self::sampleReport());
    }

    #[Route('/document.pdf', name: 'app_design_document_pdf', methods: ['GET'])]
    public function documentPdf(PdfRenderer $pdf): Response
    {
        return new Response($pdf->renderTemplate('design/document.html.twig', self::sampleReport()), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="balance-exemple.pdf"',
        ]);
    }

    /**
     * The opening balance of the demonstration dossier's general accounts (Menuiserie Vasseur,
     * 01/01/2026): an extract, so its totals do not balance.
     *
     * @return array{orientation: string, report: array<string, mixed>, rows: list<array{account: string, label: string, debit: Money, credit: Money}>, total_debit: Money, total_credit: Money}
     */
    private static function sampleReport(): array
    {
        $accounts = [
            ['205000', 'Logiciels', 680000, 0],
            ['207000', 'Fonds commercial', 3800000, 0],
            ['215400', 'Matériel industriel', 6670000, 0],
            ['218100', 'Installations générales, agencements, aménagements divers', 4200000, 0],
            ['218200', 'Matériel de transport', 5550000, 0],
            ['218300', 'Matériel de bureau et matériel informatique', 420000, 0],
            ['218400', 'Mobilier', 560000, 0],
            ['275100', 'Dépôts versés', 640000, 0],
            ['280500', 'Amortissements des logiciels', 0, 642223],
            ['281540', 'Amortissements du matériel industriel', 0, 4301496],
            ['281810', 'Amortissements des agencements', 0, 2765000],
            ['281820', 'Amortissements du matériel de transport', 0, 3384167],
            ['281830', 'Amortissements du matériel informatique', 0, 274556],
            ['281840', 'Amortissements du mobilier', 0, 336000],
            ['101300', 'Capital souscrit - appelé, versé', 0, 8000000],
            ['106100', 'Réserve légale', 0, 800000],
            ['106800', 'Autres réserves', 0, 9150000],
            ['110000', 'Report à nouveau - solde créditeur', 0, 387035],
            ['120000', 'Résultat de l’exercice - bénéfice', 0, 3642500],
            ['145000', 'Amortissements dérogatoires', 0, 59284],
            ['164100', 'Emprunt Banque Populaire 2023 (fourgon, plaqueuse)', 0, 2102229],
            ['310000', 'Matières premières et fournitures', 4135218, 0],
            ['321000', 'Matières consommables', 495310, 0],
            ['331000', 'Produits en cours', 1194000, 0],
            ['355000', 'Produits finis', 784780, 0],
            ['391000', 'Dépréciations des matières premières et fournitures', 0, 115000],
        ];
        $rows = [];
        foreach ($accounts as [$account, $label, $debit, $credit]) {
            $rows[] = ['account' => $account, 'label' => $label, 'debit' => Money::cents($debit), 'credit' => Money::cents($credit)];
        }

        return [
            'orientation' => 'landscape',
            'report' => [
                'company' => 'Menuiserie Vasseur SARL',
                'title' => 'Balance d’ouverture (extrait)',
                'fiscalYear' => 'Exercice 2026',
                'period' => 'à-nouveaux du 01/01/2026',
                'dossierDate' => new \DateTimeImmutable('2026-11-30'),
                'student' => 'Manon Chevalier',
            ],
            'rows' => $rows,
            'total_debit' => Money::sum(array_column($rows, 'debit')),
            'total_credit' => Money::sum(array_column($rows, 'credit')),
        ];
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
