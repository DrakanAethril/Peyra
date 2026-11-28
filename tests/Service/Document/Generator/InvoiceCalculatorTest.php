<?php

declare(strict_types=1);

namespace App\Tests\Service\Document\Generator;

use App\Enum\Document\InvoiceFault;
use App\Enum\Document\InvoiceMention;
use App\Service\Document\Generator\InvoiceCalculator;
use App\Service\Document\Generator\InvoiceLine;
use App\Service\Document\Generator\InvoiceOperation;
use App\Service\Document\VatLine;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

/**
 * What a generated invoice prints (step 2.1b), on figures worked out by hand: the Scierie's
 * FA-2611-0457 of mockup 1 (2,40 m³ × 1 150,00 = 2 760,00; remise − 138,00; port 85,00; HT
 * 2 707,00, TVA 20 % 541,40, TTC 3 248,40) and an invoice at three rates.
 */
final class InvoiceCalculatorTest extends TestCase
{
    public function testTheScierieInvoiceAddsUp(): void
    {
        $printed = InvoiceCalculator::print($this->scierie(), new \DateTimeImmutable('2026-12-12'));

        self::assertSame([276000, -13800, 8500], array_map(static fn (array $row): int => $row['amount']->cents, $printed->lines));
        self::assertEquals([new VatLine('20', Money::cents(270700), Money::cents(54140))], $printed->vatLines);
        self::assertSame(270700, $printed->totalExcludingTax->cents);
        self::assertSame(54140, $printed->totalVat->cents);
        self::assertSame(324840, $printed->totalIncludingTax->cents);
        self::assertSame('FA-2611-0457', $printed->number);
        self::assertSame('2026-12-12', $printed->dueDate?->format('Y-m-d'));
        self::assertSame('CF-0098', $printed->orderReference);
        self::assertCount(\count(InvoiceMention::cases()), $printed->mentions);
        self::assertFalse($printed->wrongRecipient);
    }

    /**
     * 3 × 12,35 = 37,05 at 20 % (VAT 7,41); 2,5 × 7,99 = 19,975 → 19,98 at 5,5 % (VAT 1,0989 →
     * 1,10); 1 × 100,00 at 10 % (VAT 10,00); a second line at 20 %, 0,125 × 40,00 = 5,00 (VAT on
     * 42,05: 8,41). HT 162,03, TVA 19,51, TTC 181,54; the highest rate first.
     */
    public function testTheVatIsComputedOncePerRateOnItsBases(): void
    {
        $operation = $this->operation([
            ['Vis inox', '3', 1235, '20'],
            ['Huile de lin, l', '2.5', 799, '5.5'],
            ['Pose', '1', 10000, '10'],
            ['Chevilles, boîte', '0.125', 4000, '20'],
        ]);
        $printed = InvoiceCalculator::print($operation, new \DateTimeImmutable('2026-04-30'));

        self::assertSame([3705, 1998, 10000, 500], array_map(static fn (array $row): int => $row['amount']->cents, $printed->lines));
        self::assertEquals([
            new VatLine('20', Money::cents(4205), Money::cents(841)),
            new VatLine('10', Money::cents(10000), Money::cents(1000)),
            new VatLine('5.5', Money::cents(1998), Money::cents(110)),
        ], $printed->vatLines);
        self::assertSame(16203, $printed->totalExcludingTax->cents);
        self::assertSame(1951, $printed->totalVat->cents);
        self::assertSame(18154, $printed->totalIncludingTax->cents);
    }

    public function testAMiscalculatedVatSwapsItsLastTwoDigitsAndTheTotalsFollow(): void
    {
        $printed = InvoiceCalculator::print($this->scierie([InvoiceFault::VatMiscalculated]), new \DateTimeImmutable('2026-12-12'));

        self::assertSame(54104, $printed->vatLines[0]->vat->cents, '541,40 printed 541,04.');
        self::assertSame(270700, $printed->vatLines[0]->base->cents);
        self::assertSame(54104, $printed->totalVat->cents);
        self::assertSame(324804, $printed->totalIncludingTax->cents, '2 707,00 + 541,04.');
    }

    public function testAWrongTotalSwapsTheUnitsAndTensOfItsEuros(): void
    {
        $printed = InvoiceCalculator::print($this->scierie([InvoiceFault::WrongTotal]), new \DateTimeImmutable('2026-12-12'));

        self::assertSame(54140, $printed->totalVat->cents);
        self::assertSame(328440, $printed->totalIncludingTax->cents, '3 248,40 printed 3 284,40.');

        $both = InvoiceCalculator::print($this->scierie([InvoiceFault::VatMiscalculated, InvoiceFault::WrongTotal]), new \DateTimeImmutable('2026-12-12'));
        self::assertSame(328404, $both->totalIncludingTax->cents, '3 248,04 printed 3 284,04.');
    }

    public function testAFigureWhoseDigitsAreAlikeIsRaisedInstead(): void
    {
        self::assertSame(54154, InvoiceCalculator::misprint(54144, 0), '541,44 → 541,54.');
        self::assertSame(334340, InvoiceCalculator::misprint(333340, 2), '3 333,40 → 3 343,40.');
        self::assertSame(10, InvoiceCalculator::misprint(1, 0), '0,01 → 0,10.');
        self::assertSame(-54104, InvoiceCalculator::misprint(-54140, 0));
    }

    public function testAMissingNumberOrDueDateIsNotPrintedNorRead(): void
    {
        $withoutNumber = InvoiceCalculator::print($this->scierie([], InvoiceMention::Number), new \DateTimeImmutable('2026-12-12'));
        self::assertNull($withoutNumber->number);
        self::assertFalse($withoutNumber->shows('number'));
        self::assertTrue($withoutNumber->shows('due_date'));

        $withoutDueDate = InvoiceCalculator::print($this->scierie([], InvoiceMention::DueDate), new \DateTimeImmutable('2026-12-12'));
        self::assertNull($withoutDueDate->dueDate);
        self::assertSame('FA-2611-0457', $withoutDueDate->number);

        $withoutVatNumber = InvoiceCalculator::print($this->scierie([InvoiceFault::WrongRecipient], InvoiceMention::SupplierVatNumber), new \DateTimeImmutable('2026-12-12'));
        self::assertFalse($withoutVatNumber->shows('supplier_vat_number'));
        self::assertSame(324840, $withoutVatNumber->totalIncludingTax->cents, 'A mention left out changes no figure.');
        self::assertTrue($withoutVatNumber->wrongRecipient);
    }

    /** @param list<InvoiceFault> $faults */
    private function scierie(array $faults = [], ?InvoiceMention $missing = null): InvoiceOperation
    {
        return $this->operation([
            ['Chêne avivé 27 mm, m³', '2.40', 115000, '20'],
            ['Remise 5 %', '1', -13800, '20'],
            ['Port', '1', 8500, '20'],
        ], $faults, $missing);
    }

    /**
     * @param list<array{string, string, int, string}> $lines
     * @param list<InvoiceFault>                       $faults
     */
    private function operation(array $lines, array $faults = [], ?InvoiceMention $missing = null): InvoiceOperation
    {
        return new InvoiceOperation(
            'F0001',
            'FA-2611-0457',
            new \DateTimeImmutable('2026-11-12'),
            null,
            'CF-0098',
            array_map(static fn (array $line): InvoiceLine => new InvoiceLine($line[0], BigDecimal::of($line[1]), Money::cents($line[2]), BigDecimal::of($line[3])), $lines),
            $missing,
            $faults,
        );
    }
}
