<?php

declare(strict_types=1);

namespace App\Service\Document\Generator;

use App\Enum\Document\InvoiceFault;
use App\Enum\Document\InvoiceMention;

/**
 * An invoice to generate once InvoiceRules accepted it (step 2.1b): the supplier by its code, the
 * invoice's number, date and due date (null: the supplier's payment terms decide), its order
 * reference, its lines, the mention left out and the faults asked for. Kept on the pièce it made
 * (Document::$generation), by code and never by id, so that a copy of the dossier reads it alike.
 */
final readonly class InvoiceOperation
{
    /**
     * @param list<InvoiceLine>   $lines
     * @param list<InvoiceFault>  $faults
     */
    public function __construct(
        public string $supplier,
        public string $number,
        public \DateTimeImmutable $date,
        public ?\DateTimeImmutable $dueDate,
        public ?string $orderReference,
        public array $lines,
        public ?InvoiceMention $missingMention,
        public array $faults,
    ) {
    }

    public function has(InvoiceFault $fault): bool
    {
        return \in_array($fault, $this->faults, true);
    }

    /**
     * What the pièce keeps of it.
     *
     * @return array{supplier: string, number: string, date: string, dueDate: ?string, orderReference: ?string, lines: list<array{designation: string, quantity: string, unitPrice: int, rate: string}>, missingMention: ?string, faults: list<string>}
     */
    public function toArray(): array
    {
        return [
            'supplier' => $this->supplier,
            'number' => $this->number,
            'date' => $this->date->format('Y-m-d'),
            'dueDate' => $this->dueDate?->format('Y-m-d'),
            'orderReference' => $this->orderReference,
            'lines' => array_map(static fn (InvoiceLine $line): array => $line->toArray(), $this->lines),
            'missingMention' => $this->missingMention?->value,
            'faults' => array_map(static fn (InvoiceFault $fault): string => $fault->value, $this->faults),
        ];
    }
}
