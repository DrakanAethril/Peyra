<?php

declare(strict_types=1);

namespace App\Tests\Service\Document\Generator;

use App\Enum\Document\InvoiceFault;
use App\Enum\Document\InvoiceMention;
use App\Service\Document\Generator\InvoiceCharter;
use App\Service\Document\Generator\InvoiceOrder;
use App\Service\Document\Generator\InvoiceRules;
use PHPUnit\Framework\TestCase;

/** The one reading of an invoice to generate (step 2.1b). */
final class InvoiceRulesTest extends TestCase
{
    public function testAWholeOrderBecomesItsOperation(): void
    {
        $order = InvoiceOrder::fromParameters($this->scierie() + ['dueDate' => '2026-12-12', 'missingMention' => 'supplier_vat_number', 'faults' => ['wrong_total', 'duplicate', 'unknown']]);
        $operation = InvoiceRules::check($order, static fn (string $code): bool => 'F0001' === $code);

        self::assertNotNull($operation, implode(' ', $order->errors));
        self::assertSame('F0001', $operation->supplier);
        self::assertSame('2026-11-12', $operation->date->format('Y-m-d'));
        self::assertSame('2026-12-12', $operation->dueDate?->format('Y-m-d'));
        self::assertCount(3, $operation->lines);
        self::assertSame('2.4', (string) $operation->lines[0]->quantity->strippedOfTrailingZeros(), '« 2,40 » typed the French way.');
        self::assertSame(-13800, $operation->lines[1]->unitPrice->cents);
        self::assertSame(InvoiceMention::SupplierVatNumber, $operation->missingMention);
        self::assertSame([InvoiceFault::WrongTotal, InvoiceFault::Duplicate], $operation->faults, 'An unknown fault is dropped.');
        self::assertSame(['designation' => 'Remise 5 %', 'quantity' => '1', 'unitPrice' => -13800, 'rate' => '20'], $operation->toArray()['lines'][1]);
    }

    public function testTheOrderTravelsThroughTheAddressUnchanged(): void
    {
        $order = InvoiceOrder::fromParameters($this->scierie() + ['faults' => ['vat_miscalculated']]);
        $again = InvoiceOrder::fromParameters($order->toParameters());

        self::assertEquals($order, $again);
        self::assertArrayNotHasKey('line_designation_3', $order->toParameters(), 'Blank rows are not carried.');
    }

    public function testEachFaultIsNamedUnderItsField(): void
    {
        $order = InvoiceOrder::fromParameters([
            'supplier' => 'C0019',
            'date' => '2026-11-31',
            'line_designation_0' => 'Chêne',
            'line_quantity_0' => '0',
            'line_rate_0' => '200',
            'line_quantity_1' => '2,4567',
            'missingMention' => 'signature',
        ]);

        self::assertNull(InvoiceRules::check($order, static fn (string $code): bool => 'F0001' === $code));
        self::assertSame([
            'supplier' => 'Ce fournisseur n’est pas un tiers du dossier.',
            'number' => 'Indiquez le numéro de la facture.',
            'date' => 'Une date au format JJ/MM/AAAA.',
            'line.0.quantity' => 'Une quantité positive, trois décimales au plus (2,40).',
            'line.0.price' => 'Indiquez le prix unitaire hors taxe.',
            'line.0.rate' => 'Un taux de 0 à 100 %, trois décimales au plus (20 ou 5,5).',
            'line.1.designation' => 'Indiquez ce qui est facturé.',
            'line.1.quantity' => 'Une quantité positive, trois décimales au plus (2,40).',
            'line.1.price' => 'Indiquez le prix unitaire hors taxe.',
            'line.1.rate' => 'Indiquez le taux de TVA.',
            'missingMention' => 'Choisissez une mention de la liste.',
        ], $order->errors);
    }

    public function testAnInvoiceHasALineAPositiveTotalAndADueDateAfterItsDate(): void
    {
        $empty = InvoiceOrder::fromParameters(['supplier' => 'F0001', 'number' => 'F1', 'date' => '2026-11-12']);
        self::assertNull(InvoiceRules::check($empty, static fn (): bool => true));
        self::assertSame(['lines' => 'Décrivez au moins une ligne de la facture.'], $empty->errors);

        $credit = InvoiceOrder::fromParameters(['supplier' => 'F0001', 'number' => 'F1', 'date' => '2026-11-12', 'dueDate' => '2026-11-11', 'line_designation_0' => 'Remise', 'line_quantity_0' => '1', 'line_price_0' => '-100', 'line_rate_0' => '20']);
        self::assertNull(InvoiceRules::check($credit, static fn (): bool => true));
        self::assertSame([
            'dueDate' => 'L’échéance ne peut pas précéder la date de la facture.',
            'lines' => 'Le total hors taxe de la facture est positif : une remise ne dépasse pas les lignes.',
        ], $credit->errors);
    }

    public function testASupplierAlwaysPrintsWithTheSameLook(): void
    {
        $scierie = InvoiceCharter::of('F0001', 'Scierie du Haut-Forez');
        self::assertEquals($scierie, InvoiceCharter::of('F0001', 'Scierie du Haut-Forez'));
        self::assertSame('SH', $scierie->monogram, 'The first two words of three letters or more.');
        self::assertSame('QF', InvoiceCharter::of('F0002', 'Quincaillerie Faure')->monogram);
        self::assertSame('B', InvoiceCharter::of('F0009', 'BM')->monogram);
        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $scierie->colour);

        $layouts = [];
        foreach (['F0001', 'F0002', 'F0003', 'F0004', 'F0005', 'F0006'] as $code) {
            $layouts[InvoiceCharter::of($code, 'Fournisseur')->layout->value] = true;
        }
        self::assertCount(3, $layouts, 'Six suppliers spread over the three layouts.');
    }

    /** @return array<string, string> */
    private function scierie(): array
    {
        return [
            'supplier' => 'F0001',
            'number' => 'FA-2611-0457',
            'date' => '2026-11-12',
            'orderReference' => 'CF-0098',
            'line_designation_0' => 'Chêne avivé 27 mm, m³',
            'line_quantity_0' => '2,40',
            'line_price_0' => '115000',
            'line_rate_0' => '20',
            'line_designation_1' => 'Remise 5 %',
            'line_quantity_1' => '1',
            'line_price_1' => '-13800',
            'line_rate_1' => '20',
            'line_designation_2' => 'Port',
            'line_quantity_2' => '1',
            'line_price_2' => '8500',
            'line_rate_2' => '20',
        ];
    }
}
