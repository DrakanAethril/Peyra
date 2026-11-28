<?php

declare(strict_types=1);

namespace App\Tests\Service\Purchasing;

use App\Entity\Document\Document;
use App\Entity\Dossier\Dossier;
use App\Entity\Sales\SalesDocument;
use App\Enum\Document\DocumentType;
use App\Enum\Purchasing\ReceiptStatus;
use App\Enum\Sales\SalesDocumentType;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Service\Document\Processing\PieceProcessing;
use App\Service\Document\VatLine;
use App\Service\Purchasing\PurchaseMatching;
use App\Service\Purchasing\Receipts;
use App\Service\Sales\SalesDocumentDraft;
use App\Service\Sales\SalesDocuments;
use App\Service\Shared\Money;
use App\Tests\Support\BuildsLedger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The purchasing side through the commercial documents' door (step 2.4), in BuildsLedger's dossier
 * (date 15/03/2026, supplier F0001): a supplier order is received in two receipts, each opened on
 * what is left, one draft at a time; the supplier's invoice, a pièce, is matched to them by the
 * order reference it reads; what is received and not invoiced is listed.
 */
final class PurchasingTest extends KernelTestCase
{
    use BuildsLedger;

    public function testAnOrderIsReceivedInTwoReceipts(): void
    {
        $dossier = $this->ledgerDossier();
        $documents = self::getContainer()->get(SalesDocuments::class);
        $receipts = self::getContainer()->get(Receipts::class);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $wrong = self::orderDraft('C0019');
        self::assertNull($documents->check($wrong, $dossier, SalesDocumentType::PurchaseOrder));
        self::assertSame('Le tiers C0019 n’est pas un fournisseur.', $wrong->error('customer'));

        $order = $this->order($dossier);
        self::assertSame('CF-2026-0001', $order->getNumber());
        self::assertSame(ReceiptStatus::Waiting, $receipts->status($order));
        $first = $documents->transform($order, SalesDocumentType::Receipt, 'test');
        $entityManager->flush();
        self::assertSame(['1.8', 0], [(string) $first->getLines()[0]->quantity->strippedOfTrailingZeros(), $first->getLines()[0]->sourceLine]);
        self::assertSame('Une réception de CF-2026-0001 est en brouillon : validez-la ou supprimez-la avant d’en ouvrir une autre.', $documents->transformRefusal($order, SalesDocumentType::Receipt));

        $draft = SalesDocumentDraft::of($first);
        $draft->lines[0]['quantity'] = '1';
        $documents->update($first, $documents->check($draft, $dossier, SalesDocumentType::Receipt) ?? throw new \LogicException(implode(' ', $draft->errors)), 'test');
        $entityManager->flush();
        $documents->validate($first, 'test');
        self::assertSame('BR-2026-0001', $first->getNumber());
        self::assertSame('Bon de réception ne se transforme pas en commande fournisseur.', $documents->transformRefusal($first, SalesDocumentType::PurchaseOrder));
        self::assertSame(ReceiptStatus::Partial, $receipts->status($order));

        $second = $documents->transform($order, SalesDocumentType::Receipt, 'test');
        $entityManager->flush();
        self::assertSame([['0.8', 0]], array_map(static fn ($line): array => [(string) $line->quantity->strippedOfTrailingZeros(), $line->sourceLine], $second->getLines()));
        $documents->validate($second, 'test');
        self::assertSame(ReceiptStatus::Received, $receipts->status($order));
        self::assertSame('La commande CF-2026-0001 est entièrement reçue.', $documents->transformRefusal($order, SalesDocumentType::Receipt));
        self::assertSame([$order, $first, $second], $documents->chain($order));

        $this->expectException(\LogicException::class);
        $documents->send($second, 'test');
    }

    public function testTheSuppliersInvoiceIsMatchedByItsOrderReference(): void
    {
        $dossier = $this->ledgerDossier();
        $documents = self::getContainer()->get(SalesDocuments::class);
        $matching = self::getContainer()->get(PurchaseMatching::class);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $order = $this->order($dossier);
        $receipt = $documents->transform($order, SalesDocumentType::Receipt, 'test');
        $entityManager->flush();
        $documents->validate($receipt, 'test');

        $uninvoiced = $matching->uninvoiced($dossier);
        self::assertCount(1, $uninvoiced);
        self::assertSame([$order, 196_650], [$uninvoiced[0]['order'], $uninvoiced[0]['toInvoice']->cents], '1,80 × 1 150,00 − 5 %.');

        $piece = $this->piece($dossier, 'cf 2026 0001', 196_650);
        $controls = self::getContainer()->get(PieceProcessing::class)->controls($piece);
        self::assertContains('Rapprochée de la commande CF-2026-0001 et de la réception BR-2026-0001 (1,80 m³)', array_map(static fn ($control): string => $control->message, $controls));
        self::assertSame('Mentions obligatoires de la facture : à contrôler par vos soins', $controls[\count($controls) - 1]->message, 'The mentions stay last.');
        self::assertSame([], $matching->uninvoiced($dossier));

        $twice = $this->piece($dossier, 'CF-2026-0001', 196_650);
        $fault = array_values(array_filter(self::getContainer()->get(PieceProcessing::class)->controls($twice), static fn ($control): bool => !$control->passed && str_starts_with($control->message, 'Écart')));
        self::assertCount(1, $fault, 'A second invoice of the same receipt is a gap: a duplicate?');
        self::assertFalse($fault[0]->blocking, 'At « Avertissements » it warns.');
    }

    private function order(Dossier $dossier): SalesDocument
    {
        $documents = self::getContainer()->get(SalesDocuments::class);
        $draft = self::orderDraft('F0001');
        $order = $documents->create($dossier, SalesDocumentType::PurchaseOrder, $documents->check($draft, $dossier, SalesDocumentType::PurchaseOrder) ?? throw new \LogicException(implode(' ', $draft->errors)), 'test');
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $documents->validate($order, 'test');

        return $order;
    }

    private static function orderDraft(string $supplier): SalesDocumentDraft
    {
        $draft = new SalesDocumentDraft();
        $draft->customer = $supplier;
        $draft->date = '2026-03-02';
        $draft->readLines([['article' => '', 'designation' => 'Chêne avivé 27 mm', 'unit' => 'm³', 'quantity' => '1,8', 'unitPrice' => '1 150,00', 'discount' => '5', 'rate' => '20']]);

        return $draft;
    }

    /** A supplier's invoice read: HT at 20 %, the order reference as printed. */
    private function piece(Dossier $dossier, string $reference, int $excludingTax): Document
    {
        $supplier = self::getContainer()->get(ThirdPartyRepository::class)->findOneByCode($dossier, 'F0001') ?? throw new \LogicException('F0001');
        $vat = intdiv($excludingTax, 5);
        $piece = new Document($dossier, DocumentType::PurchaseInvoice, 'dossiers/'.$dossier->getId().'/pieces/fa.pdf', 'FA-2603-0099.pdf', 'application/pdf', new \DateTimeImmutable('2026-03-15'), null);
        $piece->read(DocumentType::PurchaseInvoice, $supplier, 'FA-2603-0099', new \DateTimeImmutable('2026-03-10'), null, $reference, [new VatLine('20', Money::cents($excludingTax), Money::cents($vat))], Money::cents($excludingTax), Money::cents($vat), Money::cents($excludingTax + $vat));
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($piece);
        $entityManager->flush();

        return $piece;
    }
}
