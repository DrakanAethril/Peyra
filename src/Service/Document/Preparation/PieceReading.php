<?php

declare(strict_types=1);

namespace App\Service\Document\Preparation;

use App\Entity\Document\Document;
use App\Service\Document\VatLine;
use App\Service\Shared\Money;

/**
 * A pièce's read data as the answer key keeps them (step 2.2b): the third party by its **code**,
 * never by its id - the answer key is JSON, which a copy of the dossier carries as it is -, dates
 * as ISO days, amounts in cents. `with()` puts a wrong value on one target of the reading.
 */
final readonly class PieceReading
{
    /** @param list<VatLine> $vatLines */
    public function __construct(
        public string $type,
        public ?string $thirdParty,
        public ?string $number,
        public ?string $date,
        public ?string $dueDate,
        public ?string $orderReference,
        public array $vatLines,
        public ?Money $totalExcludingTax,
        public ?Money $totalVat,
        public ?Money $totalIncludingTax,
    ) {
    }

    public static function of(Document $document): self
    {
        return new self(
            $document->getType()->value,
            $document->getThirdParty()?->getCode(),
            $document->getNumber(),
            $document->getDocumentDate()?->format('Y-m-d'),
            $document->getDueDate()?->format('Y-m-d'),
            $document->getOrderReference(),
            $document->getVatLines(),
            $document->getTotalExcludingTax(),
            $document->getTotalVat(),
            $document->getTotalIncludingTax(),
        );
    }

    /**
     * The value a reading target holds, as an error writes it (a third party's code, cents, a
     * rate's canonical text); null when the target is not on this reading.
     */
    public function value(string $target): ?string
    {
        if (1 === preg_match('/^vat\.(\d)\.rate$/', $target, $match)) {
            return $this->vatLines[(int) $match[1]]->rate ?? null;
        }

        return match ($target) {
            SlippedErrors::THIRD_PARTY => $this->thirdParty,
            SlippedErrors::TOTAL_EXCLUDING_TAX => null === $this->totalExcludingTax ? null : (string) $this->totalExcludingTax->cents,
            SlippedErrors::TOTAL_VAT => null === $this->totalVat ? null : (string) $this->totalVat->cents,
            SlippedErrors::TOTAL_INCLUDING_TAX => null === $this->totalIncludingTax ? null : (string) $this->totalIncludingTax->cents,
            default => null,
        };
    }

    /** The same reading with a wrong value on one target (checked by SlippedErrors beforehand). */
    public function with(string $target, string $wrong): self
    {
        $thirdParty = $this->thirdParty;
        $vatLines = $this->vatLines;
        $excluding = $this->totalExcludingTax;
        $vat = $this->totalVat;
        $including = $this->totalIncludingTax;
        if (1 === preg_match('/^vat\.(\d)\.rate$/', $target, $match) && isset($vatLines[(int) $match[1]])) {
            $line = $vatLines[(int) $match[1]];
            $vatLines[(int) $match[1]] = new VatLine($wrong, $line->base, $line->vat);
        }
        match ($target) {
            SlippedErrors::THIRD_PARTY => $thirdParty = $wrong,
            SlippedErrors::TOTAL_EXCLUDING_TAX => $excluding = Money::cents((int) $wrong),
            SlippedErrors::TOTAL_VAT => $vat = Money::cents((int) $wrong),
            SlippedErrors::TOTAL_INCLUDING_TAX => $including = Money::cents((int) $wrong),
            default => null,
        };

        return new self($this->type, $thirdParty, $this->number, $this->date, $this->dueDate, $this->orderReference, $vatLines, $excluding, $vat, $including);
    }

    /**
     * @return array{type: string, thirdParty: ?string, number: ?string, date: ?string, dueDate: ?string, orderReference: ?string, vat: list<array{rate: string, base: int, vat: int}>, totalExcludingTax: ?int, totalVat: ?int, totalIncludingTax: ?int}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'thirdParty' => $this->thirdParty,
            'number' => $this->number,
            'date' => $this->date,
            'dueDate' => $this->dueDate,
            'orderReference' => $this->orderReference,
            'vat' => array_map(static fn (VatLine $line): array => $line->toArray(), $this->vatLines),
            'totalExcludingTax' => $this->totalExcludingTax?->cents,
            'totalVat' => $this->totalVat?->cents,
            'totalIncludingTax' => $this->totalIncludingTax?->cents,
        ];
    }

    /** @param array<mixed> $stored as toArray() wrote it */
    public static function fromArray(array $stored): self
    {
        $vat = [];
        foreach (\is_array($stored['vat'] ?? null) ? $stored['vat'] : [] as $row) {
            if (\is_array($row)) {
                $vat[] = VatLine::fromArray($row);
            }
        }
        $type = $stored['type'] ?? null;

        return new self(
            \is_string($type) ? $type : '',
            self::text($stored, 'thirdParty'),
            self::text($stored, 'number'),
            self::text($stored, 'date'),
            self::text($stored, 'dueDate'),
            self::text($stored, 'orderReference'),
            $vat,
            self::money($stored, 'totalExcludingTax'),
            self::money($stored, 'totalVat'),
            self::money($stored, 'totalIncludingTax'),
        );
    }

    /** @param array<mixed> $row */
    private static function text(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return \is_string($value) ? $value : null;
    }

    /** @param array<mixed> $row */
    private static function money(array $row, string $key): ?Money
    {
        $value = $row[$key] ?? null;

        return \is_int($value) ? Money::cents($value) : null;
    }
}
