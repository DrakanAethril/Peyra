<?php

declare(strict_types=1);

namespace App\Tests\Service\Shared\Pdf;

use App\Service\Shared\Money;
use App\Service\Shared\Pdf\PdfRenderer;
use App\Service\Shared\Pdf\PdfRenderingFailed;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Twig\Environment;

/**
 * Against the real Gotenberg of the stack (compose.yaml): a PDF is only proved by being made.
 */
final class PdfRendererTest extends KernelTestCase
{
    private function renderer(): PdfRenderer
    {
        return self::getContainer()->get(PdfRenderer::class);
    }

    public function testAnHtmlDocumentBecomesAPdfOfItsPages(): void
    {
        $html = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><link rel="stylesheet" href="doc-fonts.css"><link rel="stylesheet" href="doc.css"><link rel="stylesheet" href="doc-print.css"></head>'
            .'<body class="px-docbody"><p style="break-after: page">Page une</p><p style="break-after: page">Page deux</p><p>Page trois · 2 622,00 €</p></body></html>';

        $pdf = $this->renderer()->render($html);

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertSame(3, $this->renderer()->pageCount($pdf));
    }

    public function testMergeKeepsTheOrderAndAddsThePages(): void
    {
        $renderer = $this->renderer();
        $one = $renderer->render('<!doctype html><html><head><meta charset="utf-8"></head><body>Une</body></html>');
        $two = $renderer->render('<!doctype html><html><head><meta charset="utf-8"></head><body><p style="break-after: page">Deux</p><p>Trois</p></body></html>');

        $merged = $renderer->merge($one, $two);

        self::assertSame(3, $renderer->pageCount($merged));
        self::assertSame($one, $renderer->merge($one), 'One PDF is its own merge.');
    }

    public function testAReportTemplateIsPrintedOnItsLandscapePages(): void
    {
        $rows = [];
        for ($i = 1; $i <= 80; ++$i) {
            $rows[] = ['account' => (string) (401000 + $i), 'label' => 'Fournisseur '.$i, 'debit' => Money::zero(), 'credit' => Money::cents(12345 * $i)];
        }

        $pdf = $this->renderer()->renderTemplate('design/document.html.twig', [
            'orientation' => 'landscape',
            'report_header' => ['company' => 'Menuiserie Vasseur SARL', 'title' => 'Balance « fournisseurs »', 'fiscalYear' => 'Exercice 2026', 'dossierDate' => new \DateTimeImmutable('2026-11-30')],
            'rows' => $rows,
            'total_debit' => Money::zero(),
            'total_credit' => Money::sum(array_column($rows, 'credit')),
        ]);

        self::assertGreaterThanOrEqual(3, $this->renderer()->pageCount($pdf), '80 rows of 6 mm do not hold on two landscape pages.');
        self::assertStringContainsString('/MediaBox [0 0 841.', $pdf, 'A4 landscape: 297 mm = 841.89 pt wide.');
    }

    public function testGotenbergDownIsAFailureThatSaysSo(): void
    {
        $twig = self::getContainer()->get(Environment::class);
        $renderer = new PdfRenderer(new MockHttpClient(new MockResponse('', ['http_code' => 503])), $twig, new NullLogger(), 'http://gotenberg:3000', \dirname(__DIR__, 4));

        $this->expectException(PdfRenderingFailed::class);
        $renderer->render('<!doctype html><html><body>x</body></html>');
    }

    public function testAnAnswerThatIsNotAPdfIsRefused(): void
    {
        $twig = self::getContainer()->get(Environment::class);
        $renderer = new PdfRenderer(new MockHttpClient(new MockResponse('<html>erreur</html>')), $twig, new NullLogger(), 'http://gotenberg:3000', \dirname(__DIR__, 4));

        $this->expectException(PdfRenderingFailed::class);
        $renderer->render('<!doctype html><html><body>x</body></html>');
    }
}
