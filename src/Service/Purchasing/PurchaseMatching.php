<?php

declare(strict_types=1);

namespace App\Service\Purchasing;

use App\Entity\Document\Document;
use App\Entity\Dossier\Dossier;
use App\Entity\Sales\SalesDocument;
use App\Enum\Document\DocumentState;
use App\Enum\Document\DocumentType;
use App\Enum\Purchasing\MatchStatus;
use App\Enum\Sales\SalesDocumentType;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Repository\Document\DocumentRepository;
use App\Repository\Sales\SalesDocumentRepository;
use App\Service\Document\Processing\PieceControl;
use App\Service\Sales\SalesComputation;
use App\Service\Shared\Assistance\Assistance;
use App\Service\Shared\Money;

/**
 * The readings of the purchasing side's three-way match (step 2.4): a supplier's pièce matched to
 * the validated order its read reference names (InvoiceMatch is the rule), the controls it adds to
 * the pièce's « Contrôles avant validation », its status in Achats et stocks › Factures et avoirs
 * reçus, and the receipts not yet invoiced (« factures non parvenues », lot 3's inventory). A
 * pièce counts as invoicing its order as long as it is not discarded. Read inside the dossier's
 * context, nothing written.
 */
final readonly class PurchaseMatching
{
    public function __construct(
        private DocumentRepository $documents,
        private SalesDocumentRepository $commercial,
        private Receipts $receipts,
    ) {
    }

    /** A supplier's invoice or credit note: the pièces Factures et avoirs reçus lists and the match reads. */
    public static function isSupplierPiece(Document $document): bool
    {
        $kind = $document->getThirdParty()?->getKind();

        return DocumentType::PurchaseInvoice === $document->getType() && (null === $kind || ThirdPartyKind::Supplier === $kind)
            || DocumentType::CreditNote === $document->getType() && ThirdPartyKind::Supplier === $kind;
    }

    /** « cf-2026-0001 » and « CF 2026 0001 » name the same order. */
    public static function normalized(string $reference): string
    {
        return strtoupper((string) preg_replace('/[\s\-_.\/]+/', '', $reference));
    }

    /**
     * The match of a supplier's pièce that reads an order reference; null for any other.
     *
     * @param list<Document>|null $pieces the dossier's supplier pièces when the caller already read them (a list)
     */
    public function match(Document $document, ?array $pieces = null): ?InvoiceMatching
    {
        $reference = trim($document->getOrderReference() ?? '');
        if ('' === $reference || !self::isSupplierPiece($document)) {
            return null;
        }
        $dossier = $document->getDossier();
        $order = $this->orderOf($dossier, $reference);
        if (null === $order) {
            return InvoiceMatch::of($reference, null, [], [], [], null, false, true);
        }
        $receipts = [];
        foreach ($this->receipts->of($order) as $receipt) {
            if (!$receipt->isDraft()) {
                $receipts[$receipt->getNumber() ?? ''] = $receipt->getLines();
            }
        }
        $supplier = $document->getThirdParty();
        $others = [];
        foreach ($this->invoicesOf($pieces ?? $this->supplierPieces($dossier), $order) as $other) {
            if ($other !== $document) {
                $others[] = ['number' => $other->getNumber() ?? '', 'excludingTax' => self::signed($other)];
            }
        }

        return InvoiceMatch::of(
            $reference,
            $order->getNumber(),
            $order->getLines(),
            $receipts,
            $others,
            $document->getTotalExcludingTax(),
            DocumentType::CreditNote === $document->getType(),
            null === $supplier || $supplier === $order->getThirdParty(),
            $order->getThirdParty()->getName(),
        );
    }

    /**
     * Its status in the list of the supplier's pièces: « Sans commande » when it reads no reference.
     *
     * @param list<Document> $pieces the dossier's supplier pièces
     */
    public function status(Document $document, array $pieces): MatchStatus
    {
        return $this->match($document, $pieces)->status ?? MatchStatus::NoOrder;
    }

    /**
     * The lines the match adds to « Contrôles avant validation » (maquette 1: « Rapprochée de la
     * commande CF-0098 et de la réception BR-0112 (2,40 m³) »), at the level's rules: none at 0; a
     * fault warns at A and stops the posting at G and B unless the pièce is posted with reserve;
     * quantities received short or beyond only ever warn.
     *
     * @return list<PieceControl>
     */
    public function controls(Document $document, Assistance $assistance, bool $reserved): array
    {
        $match = $this->match($document);
        if (null === $match || !$assistance->runsControls()) {
            return [];
        }
        $controls = [];
        if (null !== $match->matched) {
            $controls[] = new PieceControl($match->matched, true);
        }
        if (null !== $match->fault) {
            $controls[] = new PieceControl($match->fault, false, $assistance->blocks() && !$reserved);
        }
        if ([] !== $match->quantities) {
            $controls[] = new PieceControl(\sprintf('Écart de quantité entre la commande %s et ses réceptions : %s', $match->order, implode(' ', $match->quantities)), false);
        }

        return $controls;
    }

    /**
     * The validated orders whose receipts are worth more than what their pièces invoiced, at the
     * order's prices: the receipts not yet invoiced, oldest order first.
     *
     * @return list<array{order: SalesDocument, receipts: list<SalesDocument>, received: Money, invoiced: Money, toInvoice: Money}>
     */
    public function uninvoiced(Dossier $dossier): array
    {
        $rows = [];
        $pieces = $this->supplierPieces($dossier);
        foreach (array_reverse($this->commercial->findOfTypes($dossier, [SalesDocumentType::PurchaseOrder])) as $order) {
            if ($order->isDraft()) {
                continue;
            }
            $receipts = array_values(array_filter($this->receipts->of($order), static fn (SalesDocument $receipt): bool => !$receipt->isDraft()));
            if ([] === $receipts) {
                continue;
            }
            $received = Money::sum(array_map(static fn (SalesDocument $receipt): Money => SalesComputation::of($receipt->getLines())->excludingTax, $receipts));
            $invoiced = Money::sum(array_map(self::signed(...), $this->invoicesOf($pieces, $order)));
            $left = $received->minus($invoiced);
            if ($left->isPositive()) {
                $rows[] = ['order' => $order, 'receipts' => $receipts, 'received' => $received, 'invoiced' => $invoiced, 'toInvoice' => $left];
            }
        }

        return $rows;
    }

    /** @return list<Document> the supplier's pièces of the dossier, newest first */
    public function supplierPieces(Dossier $dossier): array
    {
        return array_values(array_filter($this->documents->findByDossier($dossier), self::isSupplierPiece(...)));
    }

    private function orderOf(Dossier $dossier, string $reference): ?SalesDocument
    {
        $wanted = self::normalized($reference);
        foreach ($this->commercial->findOfTypes($dossier, [SalesDocumentType::PurchaseOrder]) as $order) {
            if (!$order->isDraft() && self::normalized($order->getNumber() ?? '') === $wanted) {
                return $order;
            }
        }

        return null;
    }

    /**
     * @param list<Document> $pieces the dossier's supplier pièces
     *
     * @return list<Document> those not discarded whose read reference names the order
     */
    private function invoicesOf(array $pieces, SalesDocument $order): array
    {
        $number = self::normalized($order->getNumber() ?? '');

        return array_values(array_filter(
            $pieces,
            static fn (Document $piece): bool => DocumentState::Discarded !== $piece->getState() && self::normalized($piece->getOrderReference() ?? '') === $number,
        ));
    }

    /** What a pièce invoices excluding tax: a credit note gives back. */
    private static function signed(Document $piece): Money
    {
        $amount = $piece->getTotalExcludingTax() ?? Money::zero();

        return DocumentType::CreditNote === $piece->getType() ? $amount->negated() : $amount;
    }
}
