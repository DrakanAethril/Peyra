<?php

declare(strict_types=1);

namespace App\Service\Document\Generator;

use App\Entity\Document\Document;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Document\InvoiceFault;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Service\Document\Documents;
use App\Service\Document\Processing\PieceProcessing;
use App\Service\Shared\Pdf\PdfRenderer;

/**
 * The teacher's generator of pièces (lot 2, step 2.1b): an invoice described in data - a supplier
 * of the dossier, lines, rates, terms - checked by InvoiceRules, computed by InvoiceCalculator,
 * printed by Gotenberg in its supplier's look (InvoiceCharter) with the faults asked for, then
 * deposited into the dossier by Documents with what it prints as its read data - twice for a
 * « doublon ». Read inside the dossier's context; the caller flushes.
 */
final readonly class InvoiceGenerator
{
    /** The addressee of an invoice « au mauvais destinataire »: fictitious, like everything here. */
    public const array WRONG_RECIPIENT = [
        'name' => 'Ébénisterie du Lignon SARL',
        'lines' => ['3 chemin Fictif', '42110 Feurs'],
        'siren' => null,
        'vatNumber' => null,
    ];

    public function __construct(
        private ThirdPartyRepository $thirdParties,
        private PdfRenderer $renderer,
        private Documents $documents,
        private PieceProcessing $processing,
    ) {
    }

    /** @return list<ThirdParty> the dossier's suppliers, by code */
    public function suppliers(Dossier $dossier): array
    {
        return array_values(array_filter($this->thirdParties->findByDossier($dossier), static fn (ThirdParty $party): bool => ThirdPartyKind::Supplier === $party->getKind()));
    }

    /** The invoice, or null with the order's errors set. */
    public function prepare(Dossier $dossier, InvoiceOrder $order): ?PreparedInvoice
    {
        $suppliers = [];
        foreach ($this->suppliers($dossier) as $supplier) {
            $suppliers[$supplier->getCode()] = $supplier;
        }
        $operation = InvoiceRules::check($order, static fn (string $code): bool => isset($suppliers[$code]));
        if (null === $operation) {
            return null;
        }
        $supplier = $suppliers[$operation->supplier];
        $dueDate = $operation->dueDate ?? $supplier->getPaymentTerms()->dueDate($operation->date);

        return new PreparedInvoice($operation, $supplier, InvoiceCalculator::print($operation, $dueDate), InvoiceCharter::of($supplier->getCode(), $supplier->getName()));
    }

    public function pdf(Dossier $dossier, PreparedInvoice $invoice): string
    {
        $company = $dossier->getCompany();
        $recipient = $invoice->printed->wrongRecipient ? self::WRONG_RECIPIENT : [
            'name' => $company->getName(),
            'lines' => array_values(array_filter([$company->getStreet(), $company->getStreet2(), $company->getPostalCode().' '.$company->getCity()])),
            'siren' => $company->getSiren(),
            'vatNumber' => $company->getVatNumber(),
        ];

        return $this->renderer->renderTemplate($invoice->charter->layout->template(), [
            'invoice' => $invoice->printed,
            'supplier' => $invoice->supplier,
            'charter' => $invoice->charter,
            'recipient' => $recipient,
        ]);
    }

    /** @return list<Document> the pièce, twice for a « doublon » */
    public function generate(Dossier $dossier, PreparedInvoice $invoice, User $actor): array
    {
        $pdf = $this->pdf($dossier, $invoice);
        $copies = $invoice->operation->has(InvoiceFault::Duplicate) ? 2 : 1;
        $made = [];
        for ($copy = 1; $copy <= $copies; ++$copy) {
            $document = $this->documents->generated($dossier, $pdf, $invoice->fileName(), $invoice->supplier, $invoice->printed, $invoice->operation->toArray() + ['copy' => $copy], $actor);
            if ([] !== $made) {
                $document->markDuplicateOf($made[0]); // The doublon's second arrival, as « mode préparation » shows it (step 2.2b).
            }
            $this->processing->arrived($document, $actor);
            $made[] = $document;
        }

        return $made;
    }
}
