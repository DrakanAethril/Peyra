<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Entity\Document\Document;

/**
 * A pièce's read data as a person typed them on its sheet, before DocumentReadRules checks them:
 * texts as posted (dates in ISO, as Px:DateInput posts them), amounts in cents (Px:AmountInput),
 * three rows of VAT breakdown, and the error of each field (`vat.<i>.<field>` for a row).
 */
final class DocumentReading
{
    /** Rows of VAT breakdown a sheet offers. */
    public const int VAT_ROWS = 3;

    public string $type = '';
    /** The third party's id as the select posts it; empty: none. */
    public string $thirdParty = '';
    public string $number = '';
    public string $date = '';
    public string $dueDate = '';
    public string $orderReference = '';

    /** @var list<array{rate: string, base: ?int, vat: ?int}> */
    public array $vat = [];

    public ?int $totalExcludingTax = null;
    public ?int $totalVat = null;
    public ?int $totalIncludingTax = null;

    /** @var array<string, string> by field */
    public array $errors = [];

    public function __construct()
    {
        $this->vat = array_fill(0, self::VAT_ROWS, ['rate' => '', 'base' => null, 'vat' => null]);
    }

    public static function of(Document $document): self
    {
        $reading = new self();
        $reading->type = $document->getType()->value;
        $reading->thirdParty = (string) ($document->getThirdParty()?->getId() ?? '');
        $reading->number = $document->getNumber() ?? '';
        $reading->date = $document->getDocumentDate()?->format('Y-m-d') ?? '';
        $reading->dueDate = $document->getDueDate()?->format('Y-m-d') ?? '';
        $reading->orderReference = $document->getOrderReference() ?? '';
        foreach (\array_slice($document->getVatLines(), 0, self::VAT_ROWS) as $index => $line) {
            $reading->vat[$index] = ['rate' => str_replace('.', ',', $line->rate), 'base' => $line->base->cents, 'vat' => $line->vat->cents];
        }
        $reading->totalExcludingTax = $document->getTotalExcludingTax()?->cents;
        $reading->totalVat = $document->getTotalVat()?->cents;
        $reading->totalIncludingTax = $document->getTotalIncludingTax()?->cents;

        return $reading;
    }

    public function error(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }
}
