<?php

declare(strict_types=1);

namespace App\Tests\Service\Inventory\Stock;

use App\Entity\Dossier\Dossier;
use App\Entity\Inventory\StockCount;
use App\Entity\Inventory\StockMovement;
use App\Enum\Accounting\AssistanceLevel;
use App\Enum\Inventory\StockCountState;
use App\Enum\Inventory\StockMovementKind;
use App\Repository\Inventory\StockCountRepository;
use App\Repository\Inventory\StockMovementRepository;
use App\Service\Inventory\ArticleDraft;
use App\Service\Inventory\ArticleSheet;
use App\Service\Inventory\Stock\StockCountLine;
use App\Service\Inventory\Stock\StockCountRefused;
use App\Service\Inventory\Stock\StockCounts;
use App\Service\Inventory\Stock\StockMovementDraft;
use App\Service\Inventory\Stock\StockMovements;
use App\Service\Inventory\Stock\StockReader;
use App\Service\Shared\Assistance\Assistance;
use App\Tests\Support\BuildsLedger;
use Brick\Math\BigDecimal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The physical inventories' door (step 2.5b) in BuildsLedger's dossier (date 15/03/2026): a line
 * per followed article, the theoretical quantity of the place at the day, a gap to justify at B,
 * each gap an adjustment at the validation - all or nothing, refused when a later exit would lack
 * the stock -, the quantities frozen, a validated inventory never changed.
 */
final class StockCountsTest extends KernelTestCase
{
    use BuildsLedger;

    public function testEachGapBecomesAnAdjustmentAtTheValidation(): void
    {
        $dossier = $this->ledgerDossier();
        $this->article($dossier, 'MP001', 'Chêne avivé 27 mm', 'm³', true);
        $this->article($dossier, 'MP002', 'Quincaillerie', 'lot', true);
        $this->article($dossier, 'PF001', 'Fenêtre chêne', 'pièce', false);
        // MP001: 10 at 100,00 then 10 at 130,00 (CMUP 115,00), 4 out, 2 moved to the Atelier: 14 in the Dépôt principal.
        $this->record($dossier, 'MP001', StockMovementKind::Entry, '2026-03-01', '10', '100,00');
        $this->record($dossier, 'MP001', StockMovementKind::Entry, '2026-03-05', '10', '130,00');
        $this->record($dossier, 'MP001', StockMovementKind::Exit, '2026-03-08', '4');
        $this->record($dossier, 'MP001', StockMovementKind::Transfer, '2026-03-09', '2', '', 'Atelier');
        $this->record($dossier, 'MP002', StockMovementKind::Entry, '2026-03-02', '5', '20,00');

        $counts = self::getContainer()->get(StockCounts::class);
        $count = $counts->open($dossier, new \DateTimeImmutable('2026-03-10'), 'Dépôt principal', 'test');
        self::assertSame(['MP001', 'MP002'], array_map(static fn (StockCountLine $line): string => $line->article, $count->getLines()));
        self::assertSame(['14', '5'], self::plain($counts->theoretical($count)));

        $counts->save($count, self::counted($count, ['12', '6'], ['', '']), 'test');
        $blocking = Assistance::at(AssistanceLevel::Blocking);
        self::assertSame(['MP001 : l’écart de −2 est à justifier.', 'MP002 : l’écart de +1 est à justifier.'], $counts->refusals($count, $blocking));
        try {
            $counts->validate($count, $blocking, 'test');
            self::fail('A gap without its reason is refused at B.');
        } catch (StockCountRefused $refused) {
            self::assertCount(2, $refused->reasons);
        }
        self::assertSame(StockCountState::Draft, $count->getState());
        self::assertSame([], $this->adjustments($count));

        $counts->save($count, self::counted($count, ['12', '6'], ['Casse', 'Lot retrouvé']), 'test');
        $counts->validate($count, $blocking, 'test');
        self::assertSame(StockCountState::Validated, $count->getState());
        self::assertSame([
            ['MP001', '-2', '2026-03-10', 'Dépôt principal', 'Inventaire du 10/03/2026 · Dépôt principal'],
            ['MP002', '1', '2026-03-10', 'Dépôt principal', 'Inventaire du 10/03/2026 · Dépôt principal'],
        ], array_map(static fn (StockMovement $m): array => [$m->getArticle()->getCode(), (string) $m->getQuantity()->strippedOfTrailingZeros(), $m->getDate()->format('Y-m-d'), $m->getLocation(), $m->getLabel()], $this->adjustments($count)));
        self::assertSame(['14', '5'], self::plain($counts->theoretical($count)));

        // MP001: 2 out at the CMUP 115,00 leave 14 for 1 610,00; MP002: 1 in at the cost of its next exit, 20,00: 6 for 120,00.
        $reader = self::getContainer()->get(StockReader::class);
        $day = new \DateTimeImmutable('2026-03-15');
        $articles = $counts->articlesByCode($dossier);
        self::assertSame(['14', 161_000], [(string) $reader->card($articles['MP001'], $day)->closing->quantity->strippedOfTrailingZeros(), $reader->card($articles['MP001'], $day)->closing->value->cents]);
        self::assertSame(['6', 12_000], [(string) $reader->card($articles['MP002'], $day)->closing->quantity->strippedOfTrailingZeros(), $reader->card($articles['MP002'], $day)->closing->value->cents]);

        try {
            $counts->validate($count, $blocking, 'test');
            self::fail('A validated inventory is validated once.');
        } catch (StockCountRefused $refused) {
            self::assertSame(['Cet inventaire est déjà validé.'], $refused->reasons);
        }
        $this->expectException(\LogicException::class);
        $count->recount($count->getLines());
    }

    public function testAnAdjustmentALaterExitWouldLackIsRefusedWhole(): void
    {
        $dossier = $this->ledgerDossier();
        $this->article($dossier, 'MP001', 'Chêne avivé 27 mm', 'm³', true);
        $this->record($dossier, 'MP001', StockMovementKind::Entry, '2026-03-01', '12', '100,00');
        $this->record($dossier, 'MP001', StockMovementKind::Exit, '2026-03-12', '12');

        $counts = self::getContainer()->get(StockCounts::class);
        $count = $counts->open($dossier, new \DateTimeImmutable('2026-03-10'), 'Dépôt principal', 'test');
        $counts->save($count, self::counted($count, ['11'], ['']), 'test');
        // Level 0: no reason asked; but 11 on 10/03 cannot give the 12 that leave on 12/03.
        try {
            $counts->validate($count, Assistance::at(AssistanceLevel::None), 'test');
            self::fail('The stock would go below zero.');
        } catch (StockCountRefused $refused) {
            self::assertSame(['Stock insuffisant de MP001 Chêne avivé 27 mm : il manquerait 1 m³ au Dépôt principal le 12/03/2026.'], $refused->reasons);
        }
        self::assertSame(StockCountState::Draft, $count->getState());
        self::assertSame([], $this->adjustments($count));

        $id = $count->getId();
        $counts->delete($count, 'test');
        self::assertNull(self::getContainer()->get(StockCountRepository::class)->find((int) $id));
    }

    private function article(Dossier $dossier, string $code, string $designation, string $unit, bool $tracked): void
    {
        $sheet = self::getContainer()->get(ArticleSheet::class);
        $draft = new ArticleDraft();
        [$draft->code, $draft->designation, $draft->unit, $draft->vatRate, $draft->stockTracked] = [$code, $designation, $unit, '20', $tracked];
        $sheet->create($dossier, $sheet->check($draft, $dossier) ?? throw new \LogicException(implode(' ', $draft->errors)), 'test');
        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    private function record(Dossier $dossier, string $article, StockMovementKind $kind, string $date, string $quantity, string $unitCost = '', string $toLocation = ''): void
    {
        $stock = self::getContainer()->get(StockMovements::class);
        $draft = new StockMovementDraft();
        [$draft->kind, $draft->article, $draft->date, $draft->quantity, $draft->unitCost, $draft->toLocation] = [$kind->value, $article, $date, $quantity, $unitCost, $toLocation];
        $stock->record($dossier, $stock->check($draft, $dossier) ?? throw new \LogicException(implode(' ', $draft->errors)), 'test');
    }

    /**
     * @param list<string> $quantities
     * @param list<string> $reasons
     *
     * @return list<StockCountLine>
     */
    private static function counted(StockCount $count, array $quantities, array $reasons): array
    {
        return array_map(static fn (StockCountLine $line, string $quantity, string $reason): StockCountLine => $line->withCount(BigDecimal::of($quantity), $reason), $count->getLines(), $quantities, $reasons);
    }

    /** @return list<StockMovement> */
    private function adjustments(StockCount $count): array
    {
        return self::getContainer()->get(StockMovementRepository::class)->findBy(['stockCount' => $count], ['id' => 'ASC']);
    }

    /**
     * @param list<BigDecimal> $quantities
     *
     * @return list<string>
     */
    private static function plain(array $quantities): array
    {
        return array_map(static fn (BigDecimal $quantity): string => (string) $quantity->strippedOfTrailingZeros(), $quantities);
    }
}
