<?php

declare(strict_types=1);

namespace App\Tests\Service\Document;

use App\Enum\Document\DocumentType;
use App\Service\Document\DocumentReading;
use App\Service\Document\DocumentReadRules;
use PHPUnit\Framework\TestCase;

/**
 * The read data of a pièce are checked for their shape, never for their arithmetic: a faulty
 * pièce keeps what it prints.
 */
final class DocumentReadRulesTest extends TestCase
{
    public function testTheMaquettesInvoiceIsReadAsTyped(): void
    {
        $reading = self::reading();

        $facts = DocumentReadRules::check($reading, static fn (int $id): bool => 7 === $id);

        self::assertNotNull($facts);
        self::assertSame([], $reading->errors);
        self::assertSame(DocumentType::PurchaseInvoice, $facts->type);
        self::assertSame(7, $facts->thirdPartyId);
        self::assertSame('FA-2611-0457', $facts->number);
        self::assertSame('2026-11-12', $facts->date?->format('Y-m-d'));
        self::assertSame('2026-12-12', $facts->dueDate?->format('Y-m-d'));
        self::assertSame('CF-0098', $facts->orderReference);
        self::assertCount(1, $facts->vatLines);
        self::assertSame(['rate' => '20', 'base' => 270_700, 'vat' => 54_140], $facts->vatLines[0]->toArray());
        self::assertSame(324_840, $facts->totalIncludingTax?->cents);
    }

    public function testAWrongTotalIsKeptAsPrinted(): void
    {
        $reading = self::reading();
        // 2 707,00 + 541,40 = 3 248,40: the pièce prints 3 284,40.
        $reading->totalIncludingTax = 328_440;
        $reading->vat[0]['vat'] = 54_000;

        $facts = DocumentReadRules::check($reading, static fn (int $id): bool => true);

        self::assertNotNull($facts);
        self::assertSame(328_440, $facts->totalIncludingTax?->cents);
        self::assertSame(54_000, $facts->vatLines[0]->vat->cents);
    }

    public function testSeveralRatesAreWrittenCanonically(): void
    {
        $reading = self::reading();
        $reading->vat[1] = ['rate' => '5,50', 'base' => 10_000, 'vat' => 550];
        $reading->vat[2] = ['rate' => '10 %', 'base' => 20_000, 'vat' => 2_000];

        $facts = DocumentReadRules::check($reading, static fn (int $id): bool => true);

        self::assertNotNull($facts);
        self::assertSame(['20', '5.5', '10'], array_map(static fn ($line): string => $line->rate, $facts->vatLines));
    }

    public function testAnEmptyReadingIsANatureAlone(): void
    {
        $reading = new DocumentReading();
        $reading->type = 'receipt';

        $facts = DocumentReadRules::check($reading, static fn (int $id): bool => false);

        self::assertNotNull($facts);
        self::assertNull($facts->thirdPartyId);
        self::assertNull($facts->number);
        self::assertSame([], $facts->vatLines);
        self::assertNull($facts->totalIncludingTax);
    }

    public function testEachFaultIsSaidUnderItsField(): void
    {
        $reading = self::reading();
        $reading->type = 'invoice';
        $reading->thirdParty = '8';
        $reading->number = str_repeat('9', 41);
        $reading->date = '2026-02-30';
        $reading->vat[0] = ['rate' => '120', 'base' => 1_000, 'vat' => 200];
        $reading->vat[1] = ['rate' => '', 'base' => 1_000, 'vat' => null];
        $reading->vat[2] = ['rate' => '20', 'base' => null, 'vat' => 200];

        self::assertNull(DocumentReadRules::check($reading, static fn (int $id): bool => 7 === $id));
        self::assertSame(['type', 'thirdParty', 'number', 'date', 'vat.0.rate', 'vat.1.rate', 'vat.2.base'], array_keys($reading->errors));
    }

    public function testTheSameRateTwiceIsRefused(): void
    {
        $reading = self::reading();
        $reading->vat[1] = ['rate' => '20,0', 'base' => 1_000, 'vat' => 200];

        self::assertNull(DocumentReadRules::check($reading, static fn (int $id): bool => true));
        self::assertSame(['vat.1.rate' => 'Ce taux figure déjà sur une autre ligne.'], $reading->errors);
    }

    private static function reading(): DocumentReading
    {
        $reading = new DocumentReading();
        $reading->type = 'purchase_invoice';
        $reading->thirdParty = '7';
        $reading->number = ' FA-2611-0457 ';
        $reading->date = '2026-11-12';
        $reading->dueDate = '2026-12-12';
        $reading->orderReference = 'CF-0098';
        $reading->vat[0] = ['rate' => '20', 'base' => 270_700, 'vat' => 54_140];
        $reading->totalExcludingTax = 270_700;
        $reading->totalVat = 54_140;
        $reading->totalIncludingTax = 324_840;

        return $reading;
    }
}
