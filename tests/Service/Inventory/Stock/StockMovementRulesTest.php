<?php

declare(strict_types=1);

namespace App\Tests\Service\Inventory\Stock;

use App\Enum\Inventory\StockMovementKind;
use App\Service\Inventory\Stock\StockMovementDraft;
use App\Service\Inventory\Stock\StockMovementRules;
use PHPUnit\Framework\TestCase;

/** A stock movement typed by hand (step 2.5): what StockMovementRules accepts and the sentence of what it refuses. */
final class StockMovementRulesTest extends TestCase
{
    public function testAnEntryIsValuedAtItsQuantityTimesItsUnitCost(): void
    {
        // 2,85 m³ at 1 132,40 = 3 227,34; 0,333 at 10,00 = 3,33.
        $fields = self::check(self::draft('entry', 'mp001', '2,85', '1 132,40'));
        self::assertNotNull($fields);
        self::assertSame(['MP001', 322_734, 'Entrée', 'Dépôt principal'], [$fields->article, $fields->value?->cents, $fields->label, $fields->location]);
        self::assertSame(333, self::check(self::draft('entry', 'MP001', '0,333', '10'))?->value?->cents);
        self::assertNull(self::check(self::draft('exit', 'MP001', '1'))?->value, 'An exit carries no value: it is read.');
    }

    public function testRefusals(): void
    {
        $cases = [
            [self::draft('entry', 'MP001', '2'), 'unitCost', 'Une entrée a son coût unitaire hors taxes.'],
            [self::draft('exit', 'MP001', '0'), 'quantity', 'Une quantité au-dessus de zéro, trois décimales au plus.'],
            [self::draft('exit', 'MP001', '1,2345'), 'quantity', 'Une quantité au-dessus de zéro, trois décimales au plus.'],
            [self::draft('adjustment', 'MP001', '0'), 'quantity', 'Une quantité en plus ou en moins (−2), jamais nulle, trois décimales au plus.'],
            [self::draft('exit', 'SV001', '1'), 'article', 'Le stock de l’article SV001 n’est pas suivi : cochez « Suivi en stock » sur sa fiche.'],
            [self::draft('exit', 'ZZ9', '1'), 'article', 'Le dossier n’a pas d’article ZZ9.'],
            [self::draft('transfer', 'MP001', '1'), 'toLocation', 'Un dépôt est attendu : Dépôt principal, Atelier…'],
            [self::draft('transfer', 'MP001', '1', to: 'dépôt PRINCIPAL'), 'toLocation', 'Un transfert va d’un dépôt à un autre.'],
            [self::draft('exit', 'MP001', '1', date: '2026-04-01'), 'date', 'Un mouvement est daté au plus tard du 31/03/2026, la date du dossier.'],
        ];
        foreach ($cases as [$draft, $field, $sentence]) {
            self::assertNull(self::check($draft), $field);
            self::assertSame($sentence, $draft->error($field));
        }
        $minus = self::check(self::draft('adjustment', 'MP001', '−2'));
        self::assertSame('-2', (string) $minus?->quantity);
        self::assertSame(StockMovementKind::Adjustment, $minus?->kind);
    }

    private static function check(StockMovementDraft $draft): ?\App\Service\Inventory\Stock\StockMovementFields
    {
        return StockMovementRules::check($draft, static fn (string $code): ?bool => ['MP001' => true, 'SV001' => false][$code] ?? null, new \DateTimeImmutable('2026-03-31'));
    }

    private static function draft(string $kind, string $article, string $quantity, string $unitCost = '', string $to = '', string $date = '2026-03-20'): StockMovementDraft
    {
        $draft = new StockMovementDraft();
        $draft->kind = $kind;
        $draft->article = $article;
        $draft->date = $date;
        $draft->quantity = $quantity;
        $draft->unitCost = $unitCost;
        $draft->toLocation = $to;

        return $draft;
    }
}
