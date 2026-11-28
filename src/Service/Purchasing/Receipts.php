<?php

declare(strict_types=1);

namespace App\Service\Purchasing;

use App\Entity\Sales\SalesDocument;
use App\Enum\Purchasing\ReceiptStatus;
use App\Enum\Sales\SalesDocumentType;
use App\Repository\Sales\SalesDocumentRepository;

/**
 * The readings of the receipts of a supplier order (step 2.4): what its validated receipts received
 * and what is left, the order's status, and - for one receipt - the order as it stood before it.
 * ReceiptProgress is the rule; this reads the documents it needs.
 */
final readonly class Receipts
{
    public function __construct(private SalesDocumentRepository $documents)
    {
    }

    /** @return list<SalesDocument> the receipts made from the order, oldest first, drafts included */
    public function of(SalesDocument $order): array
    {
        return array_values(array_filter($this->documents->findMadeFrom($order), static fn (SalesDocument $made): bool => SalesDocumentType::Receipt === $made->getType()));
    }

    /**
     * The order's lines against its validated receipts, leaving one receipt out (the one shown).
     *
     * @return list<ReceiptLine>
     */
    public function progress(SalesDocument $order, ?SalesDocument $without = null): array
    {
        $receipts = [];
        foreach ($this->of($order) as $receipt) {
            if (!$receipt->isDraft() && $receipt !== $without) {
                $receipts[] = $receipt->getLines();
            }
        }

        return ReceiptProgress::of($order->getLines(), $receipts);
    }

    /** A validated order's status; null for any other document. */
    public function status(SalesDocument $order): ?ReceiptStatus
    {
        if (SalesDocumentType::PurchaseOrder !== $order->getType() || $order->isDraft()) {
            return null;
        }

        return ReceiptProgress::status($this->progress($order));
    }

    /** The draft receipt of the order still open, if any. */
    public function openDraft(SalesDocument $order): ?SalesDocument
    {
        foreach ($this->of($order) as $receipt) {
            if ($receipt->isDraft()) {
                return $receipt;
            }
        }

        return null;
    }
}
