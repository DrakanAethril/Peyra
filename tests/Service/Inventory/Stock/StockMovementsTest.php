<?php

declare(strict_types=1);

namespace App\Tests\Service\Inventory\Stock;

use App\Entity\Dossier\Dossier;
use App\Entity\Inventory\Article;
use App\Entity\Inventory\StockMovement;
use App\Entity\Sales\SalesDocument;
use App\Enum\Inventory\StockMovementKind;
use App\Enum\Inventory\StockValuationMethod;
use App\Enum\Sales\SalesDocumentType;
use App\Repository\Inventory\StockMovementRepository;
use App\Service\Inventory\ArticleDraft;
use App\Service\Inventory\ArticleSheet;
use App\Service\Inventory\Stock\StockMovementDraft;
use App\Service\Inventory\Stock\StockMovements;
use App\Service\Inventory\Stock\StockReader;
use App\Service\Sales\SalesDocumentDraft;
use App\Service\Sales\SalesDocuments;
use App\Tests\Support\BuildsLedger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The stock movements' door (step 2.5) in BuildsLedger's dossier (date 15/03/2026): by hand,
 * refused when the stock would go below zero on any day; made by a validated receipt (an entry at
 * the line's net) and a delivery note (an exit, refused while the stock lacks it); an article with
 * movements keeps its method and stays.
 */
final class StockMovementsTest extends KernelTestCase
{
    use BuildsLedger;

    public function testMovementsByHandNeverEmptyTheStockBeyondWhatItHolds(): void
    {
        $dossier = $this->ledgerDossier();
        $article = $this->article($dossier);
        $stock = self::getContainer()->get(StockMovements::class);

        $this->record($dossier, StockMovementKind::Entry, '2026-03-01', '10', '100,00');
        $this->record($dossier, StockMovementKind::Exit, '2026-03-10', '4');

        // 10 in on 01/03, 7 out on 05/03 leaves 3, and the 4 already out on 10/03 would leave −1.
        $late = self::draft(StockMovementKind::Exit, '2026-03-05', '7');
        self::assertNull($stock->check($late, $dossier));
        self::assertSame('Stock insuffisant de MP001 Chêne avivé 27 mm : il manquerait 1 m³ au Dépôt principal le 10/03/2026.', $late->error('quantity'));
        $future = self::draft(StockMovementKind::Exit, '2026-03-16', '1');
        self::assertNull($stock->check($future, $dossier));
        self::assertSame('Un mouvement est daté au plus tard du 15/03/2026, la date du dossier.', $future->error('date'));

        $transfer = self::draft(StockMovementKind::Transfer, '2026-03-12', '2');
        $transfer->toLocation = 'Atelier';
        $stock->record($dossier, $stock->check($transfer, $dossier) ?? throw new \LogicException(implode(' ', $transfer->errors)), 'test');
        $places = self::getContainer()->get(StockReader::class)->places($article, new \DateTimeImmutable('2026-03-15'));
        self::assertSame([['Atelier', '2'], ['Dépôt principal', '4']], array_map(static fn (array $row): array => [$row['location'], (string) $row['quantity']->strippedOfTrailingZeros()], $places));

        $sheet = self::getContainer()->get(ArticleSheet::class);
        $draft = ArticleDraft::of($article);
        $draft->valuationMethod = StockValuationMethod::Fifo->value;
        self::assertNull($sheet->check($draft, $dossier, $article));
        self::assertSame('L’article a des mouvements de stock : sa méthode de valorisation ne change plus.', $draft->error('valuationMethod'));
        self::assertSame('L’article MP001 a des mouvements de stock : il ne se supprime pas.', $sheet->deletionRefusal($article));
    }

    public function testTheReceiptBringsInAndTheDeliveryNoteTakesOut(): void
    {
        $dossier = $this->ledgerDossier();
        $article = $this->article($dossier);
        $documents = self::getContainer()->get(SalesDocuments::class);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $order = $this->validated($dossier, SalesDocumentType::PurchaseOrder, 'F0001', '1,8', '1 150,00', '5');
        $receipt = $documents->transform($order, SalesDocumentType::Receipt, 'test');
        $entityManager->flush();
        $documents->validate($receipt, 'test');
        $movements = self::getContainer()->get(StockMovementRepository::class)->findOfDocument($receipt);
        self::assertCount(1, $movements);
        // 1,80 × 1 150,00 = 2 070,00, less 5 % = 1 966,50.
        self::assertSame([StockMovementKind::Entry, '1.8', 196_650, 'Bon de réception BR-2026-0001', 'Dépôt principal'], [$movements[0]->getKind(), (string) $movements[0]->getQuantity()->strippedOfTrailingZeros(), $movements[0]->getValue()?->cents, $movements[0]->getLabel(), $movements[0]->getLocation()]);

        $tooMuch = $this->document($dossier, SalesDocumentType::Delivery, 'C0019', '3', '0,00', '');
        self::assertSame(['Stock insuffisant de MP001 Chêne avivé 27 mm : il manquerait 1,2 m³ au Dépôt principal le 15/03/2026.'], $documents->validationRefusals($tooMuch));

        $delivery = $this->validated($dossier, SalesDocumentType::Delivery, 'C0019', '1', '0,00', '');
        $out = self::getContainer()->get(StockMovementRepository::class)->findOfDocument($delivery);
        self::assertSame([StockMovementKind::Exit, '1', null], [$out[0]->getKind(), (string) $out[0]->getQuantity()->strippedOfTrailingZeros(), $out[0]->getValue()]);
        // CMUP 1 966,50 / 1,8 = 1 092,50: the exit goes out at 1 092,50, 0,80 stays for 874,00.
        $card = self::getContainer()->get(StockReader::class)->card($article, new \DateTimeImmutable('2026-03-15'));
        self::assertSame([109_250, 87_400], [$card->rows[1]->outValue?->cents, $card->closing->value->cents]);
    }

    private function article(Dossier $dossier): Article
    {
        $sheet = self::getContainer()->get(ArticleSheet::class);
        $draft = new ArticleDraft();
        $draft->code = 'MP001';
        $draft->designation = 'Chêne avivé 27 mm';
        $draft->unit = 'm³';
        $draft->purchasePrice = '1 150,00';
        $draft->vatRate = '20';
        $draft->stockTracked = true;
        $draft->minimumStock = '0,5';
        $article = $sheet->create($dossier, $sheet->check($draft, $dossier) ?? throw new \LogicException(implode(' ', $draft->errors)), 'test');
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return $article;
    }

    private function record(Dossier $dossier, StockMovementKind $kind, string $date, string $quantity, string $unitCost = ''): StockMovement
    {
        $stock = self::getContainer()->get(StockMovements::class);
        $draft = self::draft($kind, $date, $quantity, $unitCost);

        return $stock->record($dossier, $stock->check($draft, $dossier) ?? throw new \LogicException(implode(' ', $draft->errors)), 'test');
    }

    private static function draft(StockMovementKind $kind, string $date, string $quantity, string $unitCost = ''): StockMovementDraft
    {
        $draft = new StockMovementDraft();
        $draft->kind = $kind->value;
        $draft->article = 'MP001';
        $draft->date = $date;
        $draft->quantity = $quantity;
        $draft->unitCost = $unitCost;

        return $draft;
    }

    private function document(Dossier $dossier, SalesDocumentType $type, string $party, string $quantity, string $price, string $discount): SalesDocument
    {
        $documents = self::getContainer()->get(SalesDocuments::class);
        $draft = new SalesDocumentDraft();
        $draft->customer = $party;
        $draft->date = '2026-03-15';
        $draft->readLines([['article' => 'MP001', 'designation' => 'Chêne avivé 27 mm', 'unit' => 'm³', 'quantity' => $quantity, 'unitPrice' => $price, 'discount' => $discount, 'rate' => '20']]);
        $document = $documents->create($dossier, $type, $documents->check($draft, $dossier, $type) ?? throw new \LogicException(implode(' ', $draft->errors)), 'test');
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return $document;
    }

    private function validated(Dossier $dossier, SalesDocumentType $type, string $party, string $quantity, string $price, string $discount): SalesDocument
    {
        $document = $this->document($dossier, $type, $party, $quantity, $price, $discount);
        self::getContainer()->get(SalesDocuments::class)->validate($document, 'test');

        return $document;
    }
}
