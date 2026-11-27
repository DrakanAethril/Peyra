<?php

declare(strict_types=1);

namespace App\Service\Shared\Pdf;

/**
 * Gotenberg did not answer, or answered something that is not a PDF. A screen turns it into
 * « Le document n'a pas pu être produit. Réessayez dans un instant. ».
 */
final class PdfRenderingFailed extends \RuntimeException
{
}
