<?php

declare(strict_types=1);

namespace App\Tests\Service\Inventory\Stock;

use App\Service\Inventory\Stock\StockCountLine;
use App\Service\Inventory\Stock\StockCountRules;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

/**
 * The one reading of a physical inventory (step 2.5b): opening, counts typed, gaps without their
 * reason, refusals of the validation.
 */
final class StockCountRulesTest extends TestCase
{
    public function testAnInventoryOpensOnADayNoLaterThanTheDossiersInAPlace(): void
    {
        $dossierDate = new \DateTimeImmutable('2026-03-31');
        $errors = [];
        self::assertEquals([new \DateTimeImmutable('2026-03-31'), 'Atelier'], StockCountRules::opening('2026-03-31', ' Atelier ', $dossierDate, $errors));
        self::assertSame([], $errors);

        self::assertNull(StockCountRules::opening('2026-04-01', '', $dossierDate, $errors));
        self::assertSame([
            'date' => 'Un inventaire est daté au plus tard du 31/03/2026, la date du dossier.',
            'location' => 'Un dépôt est attendu : Dépôt principal, Atelier…',
        ], $errors);
        self::assertNull(StockCountRules::opening('', str_repeat('x', 41), $dossierDate, $errors));
        self::assertSame(['date', 'location'], array_keys($errors));
    }

    public function testTheCountsAreReadLineByLine(): void
    {
        $lines = [new StockCountLine('MP001'), new StockCountLine('MP002', BigDecimal::of('4'), 'Vu'), new StockCountLine('PF001')];
        $errors = [];
        $read = StockCountRules::counts($lines, [
            0 => ['counted' => '12,5', 'reason' => ' Casse '],
            1 => ['counted' => '', 'reason' => ''],
        ], $errors);

        self::assertSame([], $errors);
        self::assertNotNull($read);
        self::assertSame([
            ['article' => 'MP001', 'counted' => '12.500', 'reason' => 'Casse', 'theoretical' => null],
            // Emptied: the article is no longer counted.
            ['article' => 'MP002', 'counted' => null, 'reason' => '', 'theoretical' => null],
            // Not sent: kept as it was.
            ['article' => 'PF001', 'counted' => null, 'reason' => '', 'theoretical' => null],
        ], array_map(static fn (StockCountLine $line): array => $line->toArray(), $read));

        self::assertNull(StockCountRules::counts($lines, [
            0 => ['counted' => '-1', 'reason' => ''],
            1 => ['counted' => '1,2345', 'reason' => str_repeat('x', 161)],
            2 => ['counted' => 'douze', 'reason' => ''],
        ], $errors));
        self::assertSame([
            'line.0.counted' => 'Une quantité de zéro ou plus, trois décimales au plus.',
            'line.1.counted' => 'Une quantité de zéro ou plus, trois décimales au plus.',
            'line.1.reason' => '160 caractères au plus.',
            'line.2.counted' => 'Ce nombre n’est pas lisible. Écrivez par exemple 6,90.',
        ], $errors);
    }

    public function testAGapWithoutItsReasonStopsTheValidationWhereTheLevelAsksIt(): void
    {
        $lines = [
            new StockCountLine('MP001', BigDecimal::of('10')),          // 12 in theory: −2, no reason
            new StockCountLine('MP002', BigDecimal::of('3.5'), 'Retour'), // 3 in theory: +0,5, a reason
            new StockCountLine('MP003', BigDecimal::of('7')),           // 7 in theory: no gap
            new StockCountLine('PF001'),                                 // not counted
            new StockCountLine('PF002', BigDecimal::of('0.125')),       // 0 in theory: +0,125, no reason
        ];
        $theoretical = [BigDecimal::of('12'), BigDecimal::of('3'), BigDecimal::of('7'), BigDecimal::of('5'), BigDecimal::zero()];
        $followed = static fn (string $code): bool => 'MP003' !== $code;

        self::assertSame([0, 4], StockCountRules::unjustified($lines, $theoretical));
        self::assertSame([
            'L’article MP003 n’est plus suivi en stock : laissez sa quantité vide.',
            'MP001 : l’écart de −2 est à justifier.',
            'PF002 : l’écart de +0,125 est à justifier.',
        ], StockCountRules::refusals($lines, $theoretical, $followed, true));
        self::assertSame(['L’article MP003 n’est plus suivi en stock : laissez sa quantité vide.'], StockCountRules::refusals($lines, $theoretical, $followed, false));

        self::assertSame(['Aucun article n’est compté : saisissez au moins une quantité comptée.'], StockCountRules::refusals([new StockCountLine('PF001')], [BigDecimal::of('5')], $followed, true));
    }

    public function testALineKeepsItsTheoreticalQuantityOnceFrozen(): void
    {
        $line = (new StockCountLine('MP001', BigDecimal::of('9.5'), 'Casse'))->frozenAt(BigDecimal::of('10'));

        self::assertSame('-0.5', (string) $line->gapFrom(BigDecimal::of('10')));
        self::assertSame($line->toArray(), StockCountLine::fromArray($line->toArray())->toArray());
        self::assertSame(['article' => 'MP001', 'counted' => '9.500', 'reason' => 'Casse', 'theoretical' => '10.000'], $line->toArray());
    }
}
