<?php

declare(strict_types=1);

namespace App\Service\Sales;

use App\Entity\Sales\SalesDocument;
use App\Service\Shared\FrenchFormat;

/**
 * A commercial document as a person typed it on its fiche, before SalesDocumentRules reads it:
 * the customer's code, the dates as Px:DateInput posts them (ISO), the reference, the lines as
 * typed (amounts « 1 380,00 », quantities « 3,5 »), the cash discount in percent and whether the VAT
 * is due on receipt (step 2.3b), and the error of each field (`line.2.quantity`).
 *
 * @phpstan-type LineRow array{article: string, designation: string, unit: string, quantity: string, unitPrice: string, discount: string, rate: string}
 */
final class SalesDocumentDraft
{
    public string $customer = '';
    public string $date = '';
    public string $dueDate = '';
    public string $reference = '';
    public string $cashDiscount = '';
    public bool $vatOnReceipts = false;

    /** @var list<LineRow> */
    public array $lines = [];

    /** @var array<string, string> by field */
    public array $errors = [];

    public static function of(SalesDocument $document): self
    {
        $draft = new self();
        $draft->customer = $document->getCustomer()->getCode();
        $draft->date = $document->getDate()->format('Y-m-d');
        $draft->dueDate = $document->getDueDate()?->format('Y-m-d') ?? '';
        $draft->reference = $document->getReference() ?? '';
        $draft->cashDiscount = str_replace('.', ',', $document->getCashDiscount() ?? '');
        $draft->vatOnReceipts = $document->isVatOnReceipts();
        foreach ($document->getLines() as $line) {
            $draft->lines[] = [
                'article' => $line->article ?? '',
                'designation' => $line->designation,
                'unit' => $line->unit,
                'quantity' => str_replace('.', ',', (string) $line->quantity->strippedOfTrailingZeros()),
                'unitPrice' => FrenchFormat::amount($line->unitPrice),
                'discount' => null === $line->discount ? '' : str_replace('.', ',', (string) $line->discount->strippedOfTrailingZeros()),
                'rate' => str_replace('.', ',', $line->rate),
            ];
        }

        return $draft;
    }

    /**
     * The rows of the form (`lines[3][quantity]`), each field read as text.
     *
     * @param list<array<string, string>> $rows
     */
    public function readLines(array $rows): void
    {
        $this->lines = [];
        foreach ($rows as $row) {
            $this->lines[] = [
                'article' => strtoupper($row['article'] ?? ''),
                'designation' => $row['designation'] ?? '',
                'unit' => $row['unit'] ?? '',
                'quantity' => $row['quantity'] ?? '',
                'unitPrice' => $row['unitPrice'] ?? '',
                'discount' => $row['discount'] ?? '',
                'rate' => $row['rate'] ?? '',
            ];
        }
    }

    public function error(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }

    public function date(string $field): ?\DateTimeImmutable
    {
        $value = 'dueDate' === $field ? $this->dueDate : $this->date;

        return '' === $value ? null : (\DateTimeImmutable::createFromFormat('!Y-m-d', $value) ?: null);
    }

    /**
     * An empty line for the grid's template row.
     *
     * @return LineRow
     */
    public static function blankLine(): array
    {
        return ['article' => '', 'designation' => '', 'unit' => '', 'quantity' => '', 'unitPrice' => '', 'discount' => '', 'rate' => ''];
    }
}
