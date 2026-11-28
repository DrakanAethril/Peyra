<?php

declare(strict_types=1);

namespace App\Service\Document\Generator;

/**
 * An invoice to generate as the teacher described it (step 2.1b), before InvoiceRules checks it:
 * texts as posted (dates in ISO, as Px:DateInput posts them), unit prices in cents
 * (Px:AmountInput), LINE_ROWS rows of lines, the mention left out and the faults ticked, and the
 * error of each field (`line.<i>.<field>` for a row). Read from the query string for the preview
 * and from the body for the generation: the same fields both ways (toParameters()).
 */
final class InvoiceOrder
{
    /** Rows of lines the generator offers. */
    public const int LINE_ROWS = 6;

    /** The supplier's code (« F0001 »). */
    public string $supplier = '';
    public string $number = '';
    public string $date = '';
    public string $dueDate = '';
    public string $orderReference = '';

    /** @var list<array{designation: string, quantity: string, price: ?int, rate: string}> */
    public array $lines = [];

    /** An InvoiceMention's value, or empty: every mention printed. */
    public string $missingMention = '';

    /** @var list<string> InvoiceFault values */
    public array $faults = [];

    /** @var array<string, string> by field */
    public array $errors = [];

    public function __construct()
    {
        $this->lines = array_fill(0, self::LINE_ROWS, ['designation' => '', 'quantity' => '', 'price' => null, 'rate' => '']);
    }

    /** A blank order dated on the dossier's date. */
    public static function on(\DateTimeImmutable $date): self
    {
        $order = new self();
        $order->date = $date->format('Y-m-d');

        return $order;
    }

    /** @param array<array-key, mixed> $parameters a request's query or body */
    public static function fromParameters(array $parameters): self
    {
        $order = new self();
        foreach (['supplier', 'number', 'date', 'dueDate', 'orderReference', 'missingMention'] as $field) {
            $order->{$field} = self::text($parameters[$field] ?? null);
        }
        for ($i = 0; $i < self::LINE_ROWS; ++$i) {
            $price = self::text($parameters['line_price_'.$i] ?? null);
            $order->lines[$i] = [
                'designation' => self::text($parameters['line_designation_'.$i] ?? null),
                'quantity' => self::text($parameters['line_quantity_'.$i] ?? null),
                'price' => 1 === preg_match('/^-?\d{1,15}$/', $price) ? (int) $price : null,
                'rate' => self::text($parameters['line_rate_'.$i] ?? null),
            ];
        }
        $faults = $parameters['faults'] ?? [];
        foreach (\is_array($faults) ? $faults : [] as $fault) {
            if (\is_string($fault) && '' !== $fault && !\in_array($fault, $order->faults, true)) {
                $order->faults[] = $fault;
            }
        }

        return $order;
    }

    /**
     * The order as fields again, for the preview's address (no token in it: nothing here is secret).
     *
     * @return array<string, string|list<string>>
     */
    public function toParameters(): array
    {
        $parameters = [
            'supplier' => $this->supplier,
            'number' => $this->number,
            'date' => $this->date,
            'dueDate' => $this->dueDate,
            'orderReference' => $this->orderReference,
            'missingMention' => $this->missingMention,
        ];
        foreach ($this->lines as $i => $line) {
            if ('' === $line['designation'] && '' === $line['quantity'] && null === $line['price'] && '' === $line['rate']) {
                continue;
            }
            $parameters['line_designation_'.$i] = $line['designation'];
            $parameters['line_quantity_'.$i] = $line['quantity'];
            $parameters['line_price_'.$i] = null === $line['price'] ? '' : (string) $line['price'];
            $parameters['line_rate_'.$i] = $line['rate'];
        }
        if ([] !== $this->faults) {
            $parameters['faults'] = $this->faults;
        }

        return array_filter($parameters, static fn (string|array $value): bool => '' !== $value);
    }

    public function hasFault(string $fault): bool
    {
        return \in_array($fault, $this->faults, true);
    }

    public function error(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? trim((string) $value) : '';
    }
}
