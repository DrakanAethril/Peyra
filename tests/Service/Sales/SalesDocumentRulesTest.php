<?php

declare(strict_types=1);

namespace App\Tests\Service\Sales;

use App\Service\Sales\SalesDocumentDraft;
use App\Service\Sales\SalesDocumentRules;
use PHPUnit\Framework\TestCase;

/** The reading of a commercial document typed on its fiche (step 2.3). */
final class SalesDocumentRulesTest extends TestCase
{
    public function testAGoodDraftIsRead(): void
    {
        $draft = self::draft([
            ['article' => 'PF001', 'designation' => 'Fenêtre', 'unit' => 'pièce', 'quantity' => '6', 'unitPrice' => '1 380,00', 'discount' => '', 'rate' => '10'],
            ['article' => '', 'designation' => '', 'unit' => '', 'quantity' => '', 'unitPrice' => '', 'discount' => '', 'rate' => ''],
            ['article' => '', 'designation' => 'Comptoir chêne', 'unit' => 'ml', 'quantity' => '3,5', 'unitPrice' => '1150', 'discount' => '4,5', 'rate' => '5,5'],
        ]);

        $fields = self::check($draft);

        self::assertNotNull($fields, implode(' ', $draft->errors));
        self::assertSame('C0026', $fields->customer);
        self::assertCount(2, $fields->lines, 'An empty row is dropped.');
        self::assertCount(2, $draft->lines, 'The draft keeps the rows read, so the faults line up with them.');
        self::assertSame(['PF001', '6', 138_000, null, '10'], [$fields->lines[0]->article, (string) $fields->lines[0]->quantity, $fields->lines[0]->unitPrice->cents, $fields->lines[0]->discount, $fields->lines[0]->rate]);
        self::assertSame([null, '3.5', 115_000, '4.5', '5.5'], [$fields->lines[1]->article, (string) $fields->lines[1]->quantity, $fields->lines[1]->unitPrice->cents, (string) $fields->lines[1]->discount, $fields->lines[1]->rate]);
    }

    public function testEachFaultIsUnderItsField(): void
    {
        $draft = self::draft([
            ['article' => 'ZZ9', 'designation' => '', 'unit' => '', 'quantity' => '0', 'unitPrice' => '-5', 'discount' => '120', 'rate' => 'vingt'],
        ]);
        $draft->customer = 'F0001';
        $draft->dueDate = '2026-03-01';

        self::assertNull(self::check($draft));
        self::assertSame(['customer', 'dueDate', 'line.0.article', 'line.0.designation', 'line.0.quantity', 'line.0.unitPrice', 'line.0.discount', 'line.0.rate'], array_keys($draft->errors));
        self::assertSame('Le tiers F0001 n’est pas un client.', $draft->error('customer'));
        self::assertSame('L’échéance ne précède pas la date du document.', $draft->error('dueDate'));
    }

    public function testAnUnknownThirdPartyOrNoneIsSaid(): void
    {
        $draft = self::draft([]);
        $draft->customer = 'C9999';
        self::assertNull(self::check($draft));
        self::assertSame('Le dossier n’a pas de tiers C9999.', $draft->error('customer'));

        $draft->customer = '';
        $draft->date = '';
        self::assertNull(self::check($draft));
        self::assertSame(['customer', 'date'], array_keys($draft->errors));
    }

    public function testWhatAValidationAdds(): void
    {
        $dossierDate = new \DateTimeImmutable('2026-03-31');
        self::assertSame([], SalesDocumentRules::validationRefusals(2, new \DateTimeImmutable('2026-03-31'), $dossierDate, new \DateTimeImmutable('2026-03-31')));
        self::assertSame(['Le document n’a aucune ligne.'], SalesDocumentRules::validationRefusals(0, new \DateTimeImmutable('2026-03-15'), $dossierDate, null));
        self::assertSame(['Le document est daté après la date du dossier (31/03/2026).'], SalesDocumentRules::validationRefusals(1, new \DateTimeImmutable('2026-04-01'), $dossierDate, null));
        self::assertSame(['La numérotation suit l’ordre des dates : le dernier numéro de ce type est daté du 20/03/2026.'], SalesDocumentRules::validationRefusals(1, new \DateTimeImmutable('2026-03-19'), $dossierDate, new \DateTimeImmutable('2026-03-20')));
    }

    /** @param list<array<string, string>> $rows */
    private static function draft(array $rows): SalesDocumentDraft
    {
        $draft = new SalesDocumentDraft();
        $draft->customer = 'c0026';
        $draft->date = '2026-03-15';
        $draft->readLines($rows);

        return $draft;
    }

    private static function check(SalesDocumentDraft $draft): ?\App\Service\Sales\SalesDocumentFields
    {
        return SalesDocumentRules::check(
            $draft,
            static fn (string $code): ?bool => ['C0026' => true, 'F0001' => false][$code] ?? null,
            static fn (string $code): bool => 'PF001' === $code,
        );
    }
}
