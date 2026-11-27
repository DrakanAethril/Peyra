<?php

declare(strict_types=1);

namespace App\Service\Shared\Pdf;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Twig\Environment;

/**
 * The one door to Gotenberg (README of the plan, § 8): every printable document is a Twig template
 * extending doc/_document.html.twig, turned into a PDF here.
 *
 * The HTML is posted with the files it names flat - the three doc/ stylesheets, the fonts, the
 * seal - so Gotenberg never reaches the application and a document carries no network address
 * (design-ui.md § 3.6). Options: the CSS decides the page (`preferCssPageSize`, no margin added),
 * backgrounds printed, and the conversion waits for the fonts.
 */
final readonly class PdfRenderer
{
    /** Posted beside every document, by the name it uses. */
    private const array FILES = [
        'doc-fonts.css' => 'assets/styles/doc/doc-fonts.css',
        'doc.css' => 'assets/styles/doc/doc.css',
        'doc-print.css' => 'assets/styles/doc/doc-print.css',
        'inter-var-latin.woff2' => 'assets/fonts/inter-var-latin.woff2',
        'spectral-600-latin.woff2' => 'assets/fonts/spectral-600-latin.woff2',
        'IBMPlexMono-Regular-Latin1.woff2' => 'assets/fonts/IBMPlexMono-Regular-Latin1.woff2',
        'IBMPlexMono-Regular-Pi.woff2' => 'assets/fonts/IBMPlexMono-Regular-Pi.woff2',
        'IBMPlexMono-SemiBold-Latin1.woff2' => 'assets/fonts/IBMPlexMono-SemiBold-Latin1.woff2',
        'IBMPlexMono-SemiBold-Pi.woff2' => 'assets/fonts/IBMPlexMono-SemiBold-Pi.woff2',
        'seal.svg' => 'assets/brand/seal.svg',
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
        private Environment $twig,
        private LoggerInterface $logger,
        #[Autowire(env: 'GOTENBERG_URL')]
        private string $gotenbergUrl,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    /**
     * A template extending doc/_document.html.twig, as a PDF.
     *
     * @param array<string, mixed> $context
     */
    public function renderTemplate(string $template, array $context = []): string
    {
        return $this->render($this->twig->render($template, ['pdf' => true] + $context));
    }

    /** A whole HTML document whose stylesheets and fonts are named flat, as a PDF. */
    public function render(string $html): string
    {
        $fields = ['index.html' => new DataPart($html, 'index.html', 'text/html')];
        foreach (self::FILES as $name => $path) {
            $fields[$name] = DataPart::fromPath($this->projectDir.'/'.$path, $name);
        }
        $fields += [
            'preferCssPageSize' => 'true',
            'printBackground' => 'true',
            'emulatedMediaType' => 'print',
            'marginTop' => '0',
            'marginBottom' => '0',
            'marginLeft' => '0',
            'marginRight' => '0',
            'waitForExpression' => "document.fonts.status === 'loaded'",
        ];

        return $this->pdf($this->post('/forms/chromium/convert/html', $fields));
    }

    /** Several PDFs as one, in the order given. */
    public function merge(string ...$pdfs): string
    {
        if ([] === $pdfs) {
            throw new \InvalidArgumentException('Nothing to merge.');
        }
        if (1 === \count($pdfs)) {
            return $pdfs[0];
        }

        $fields = [];
        foreach (array_values($pdfs) as $index => $pdf) {
            // Gotenberg merges in alphabetical order of the file names.
            $name = \sprintf('%04d.pdf', $index + 1);
            $fields[$name] = new DataPart($pdf, $name, 'application/pdf');
        }

        return $this->pdf($this->post('/forms/pdfengines/merge', $fields));
    }

    /** How many pages a PDF has, read by Gotenberg (exiftool). */
    public function pageCount(string $pdf): int
    {
        $answer = json_decode($this->post('/forms/pdfengines/metadata/read', [
            'document.pdf' => new DataPart($pdf, 'document.pdf', 'application/pdf'),
        ]), true);
        $metadata = \is_array($answer) ? ($answer['document.pdf'] ?? null) : null;
        $count = \is_array($metadata) ? ($metadata['PageCount'] ?? null) : null;
        if (!\is_int($count) || $count < 1) {
            throw new PdfRenderingFailed('Gotenberg did not say how many pages the document has.');
        }

        return $count;
    }

    /** @param array<string, DataPart|string> $fields */
    private function post(string $path, array $fields): string
    {
        $form = new FormDataPart($fields);
        try {
            return $this->httpClient->request('POST', rtrim($this->gotenbergUrl, '/').$path, [
                'headers' => $form->getPreparedHeaders()->toArray(),
                'body' => $form->bodyToIterable(),
                'timeout' => 60,
            ])->getContent();
        } catch (ExceptionInterface $exception) {
            $this->logger->error('Gotenberg failed on {path}: {message}', ['path' => $path, 'message' => $exception->getMessage(), 'exception' => $exception]);

            throw new PdfRenderingFailed(\sprintf('Gotenberg failed on %s.', $path), previous: $exception);
        }
    }

    private function pdf(string $bytes): string
    {
        if (!str_starts_with($bytes, '%PDF-')) {
            throw new PdfRenderingFailed('Gotenberg answered something that is not a PDF.');
        }

        return $bytes;
    }
}
