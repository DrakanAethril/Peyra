<?php

declare(strict_types=1);

namespace App\Service\Sales;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\Sales\SalesDocument;
use App\Entity\Sales\SalesSequence;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Audit\AuditAction;
use App\Enum\Sales\SalesDocumentType;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Repository\Inventory\ArticleRepository;
use App\Repository\Sales\SalesDocumentRepository;
use App\Repository\Sales\SalesSequenceRepository;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Service\Accounting\Template\VatRate;
use App\Service\Audit\AuditTrail;
use App\Service\Regulatory\MissingRegulatoryValue;
use App\Service\Regulatory\RegulatoryReader;
use App\Service\Shared\FrenchFormat;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one door of the commercial documents (step 2.3): a draft created, changed, deleted; a
 * document validated - numbered under a lock on its type's sequence, so that the numbering is
 * continuous and without a gap -, transformed into the next one of the chain (or an invoice into a
 * credit note), sent (simulated: nothing leaves, the history says it went). Every gesture is in
 * the dossier's trail and in the document's history, at the dossier's date. create(), update() and
 * delete() leave the flush to the caller; validate() flushes in its own transaction.
 *
 * Step 2.3b: an order is invoiced in part by deposit invoices (`transform()` into a deposit, a
 * percentage of its bases), which its final invoice deducts - read from its chain while it is a
 * draft, frozen on it at its validation; once validated, an invoicing document goes to the books
 * (SalesPosting::validated()).
 *
 * @phpstan-import-type CustomerPrint from SalesDocument
 */
final readonly class SalesDocuments
{
    /** The rates of fiscal.vat.rates, in the order offered. */
    private const array RATES = ['normal', 'intermediate', 'reduced', 'super_reduced'];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private SalesDocumentRepository $documents,
        private SalesSequenceRepository $sequences,
        private ThirdPartyRepository $thirdParties,
        private ArticleRepository $articles,
        private AuditTrail $auditTrail,
        private RegulatoryReader $regulatory,
        private SalesPosting $posting,
    ) {
    }

    /** @return list<string> the VAT rates in force at the dossier's date (« 20 », « 5.5 »), offered on the lines; none when none is installed */
    public function ratesInForce(Dossier $dossier): array
    {
        $snapshot = $this->regulatory->at($dossier->getDossierDate());
        if (!$snapshot->has('fiscal.vat.rates')) {
            return [];
        }
        $rates = [];
        foreach (self::RATES as $name) {
            try {
                $rates[] = VatRate::canonical($snapshot->decimal('fiscal.vat.rates', $name));
            } catch (MissingRegulatoryValue) {
                continue;
            }
        }

        return $rates;
    }

    /**
     * The chain a document belongs to, from its first document to its last: the documents it was
     * made from, itself, then those made from it (a credit note or a deposit invoice ends a branch).
     *
     * @return list<SalesDocument>
     */
    public function chain(SalesDocument $document): array
    {
        $before = [];
        for ($source = $document->getSource(); null !== $source; $source = $source->getSource()) {
            array_unshift($before, $source);
        }
        $after = [];
        $current = $document;
        while (true) {
            $next = null;
            foreach ($this->documents->findMadeFrom($current) as $made) {
                $after[] = $made;
                if (SalesDocumentType::CreditNote !== $made->getType() && SalesDocumentType::Deposit !== $made->getType()) {
                    $next = $made;
                }
            }
            if (null === $next) {
                break;
            }
            $current = $next;
        }

        return [...$before, $document, ...$after];
    }

    /**
     * The deposit invoices a final invoice deducts: those frozen on it once validated; for a draft,
     * the validated deposit invoices made from the documents of its chain before it.
     *
     * @return list<SalesDeposit>
     */
    public function deposits(SalesDocument $document): array
    {
        if (SalesDocumentType::Invoice !== $document->getType()) {
            return [];
        }
        $frozen = $document->getDeposits();
        if (null !== $frozen) {
            return $frozen;
        }
        $deposits = [];
        foreach ($this->depositsBefore($document) as $deposit) {
            if (!$deposit->isDraft()) {
                $deposits[] = new SalesDeposit($deposit->getNumber() ?? '', $deposit->getDate(), $deposit->getTotals()->vatLines, $deposit->isVatOnReceipts());
            }
        }

        return $deposits;
    }

    /** Its figures, a draft final invoice deducting its chain's deposits. */
    public function totals(SalesDocument $document): SalesTotals
    {
        return $document->getTotals($this->deposits($document));
    }

    /** A new document's draft: dated the dossier's date. */
    public function blank(Dossier $dossier): SalesDocumentDraft
    {
        $draft = new SalesDocumentDraft();
        $draft->date = $dossier->getDossierDate()->format('Y-m-d');

        return $draft;
    }

    /** What may be saved, or null with the draft's errors filled. */
    public function check(SalesDocumentDraft $draft, Dossier $dossier): ?SalesDocumentFields
    {
        return SalesDocumentRules::check(
            $draft,
            function (string $code) use ($dossier): ?bool {
                $thirdParty = $this->thirdParties->findOneByCode($dossier, $code);

                return null === $thirdParty ? null : ThirdPartyKind::Customer === $thirdParty->getKind();
            },
            fn (string $code): bool => null !== $this->articles->findOneByCode($dossier, $code),
        );
    }

    public function create(Dossier $dossier, SalesDocumentType $type, SalesDocumentFields $fields, User|string $by): SalesDocument
    {
        $document = new SalesDocument($dossier, $type, $this->customer($dossier, $fields->customer), $fields->date);
        $document->rewrite($document->getCustomer(), $fields->date, $fields->dueDate, $fields->reference, $fields->lines, ...self::flags($type, $fields));
        $document->recordHistory('created', $dossier->getDossierDate(), self::name($by), 'Brouillon ouvert');
        $this->entityManager->persist($document);
        $this->auditTrail->inDossier($dossier, AuditAction::SalesDocumentCreated, $by, \sprintf('Brouillon de %s ouvert pour %s', mb_strtolower($type->label()), $document->getCustomer()->getName()), ['type' => $type->value]);

        return $document;
    }

    public function update(SalesDocument $document, SalesDocumentFields $fields, User|string $by): void
    {
        $dossier = $document->getDossier();
        $document->rewrite($this->customer($dossier, $fields->customer), $fields->date, $fields->dueDate, $fields->reference, $fields->lines, ...self::flags($document->getType(), $fields));
        $this->auditTrail->inDossier($dossier, AuditAction::SalesDocumentChanged, $by, \sprintf('Brouillon de %s modifié', mb_strtolower($document->getType()->label())), ['document' => $document->getId()]);
    }

    /** A draft only: it carries no number, so the numbering keeps no gap. */
    public function delete(SalesDocument $document, User|string $by): void
    {
        if (!$document->isDraft()) {
            throw new \LogicException('A validated commercial document is never deleted.');
        }
        $this->auditTrail->inDossier($document->getDossier(), AuditAction::SalesDocumentDeleted, $by, \sprintf('Brouillon de %s supprimé', mb_strtolower($document->getType()->label())), ['document' => $document->getId()]);
        $this->entityManager->remove($document);
    }

    /** @return list<string> why the document may not be validated now; empty when it may */
    public function validationRefusals(SalesDocument $document): array
    {
        if (!$document->isDraft()) {
            return ['Le document est déjà validé.'];
        }
        $dossier = $document->getDossier();
        $year = (int) $document->getDate()->format('Y');
        $sequence = $this->sequences->findOneBy(['dossier' => $dossier, 'type' => $document->getType(), 'year' => $year]);
        $refusals = SalesDocumentRules::validationRefusals(\count($document->getLines()), $document->getDate(), $dossier->getDossierDate(), $sequence?->getLastDate());
        $source = $document->getSource();
        if (SalesDocumentType::CreditNote === $document->getType() && null !== $source) {
            $credited = $document->getTotals()->includingTax;
            foreach ($this->documents->findValidatedCreditNotesOf($source) as $note) {
                $credited = $credited->plus($note->getTotals()->includingTax);
            }
            if ($credited->compareTo($source->getTotals()->includingTax) > 0) {
                $refusals[] = \sprintf('Les avoirs de la facture %s dépasseraient son montant (%s TTC).', $source->getNumber() ?? '', FrenchFormat::amount($source->getTotals()->includingTax));
            }
        }
        if (SalesDocumentType::Invoice === $document->getType()) {
            foreach ($this->depositsBefore($document) as $deposit) {
                if ($deposit->isDraft()) {
                    $refusals[] = 'Une facture d’acompte de la commande est encore en brouillon : validez-la ou supprimez-la avant la facture finale.';
                    break;
                }
            }
            if ($this->totals($document)->amountDue->isNegative()) {
                $refusals[] = 'Les acomptes déduits dépassent le montant de la facture.';
            }
        }

        return $refusals;
    }

    /**
     * Numbered and frozen: the next number of its type and year, read under a lock on the
     * sequence's row; its customer printed as they are now; an invoice's due date by the
     * customer's terms when none was typed; a final invoice's deposits. Flushed here, in one
     * transaction; then an invoicing document goes to the books as the dossier's behaviour says
     * (SalesPosting), which writes - or not - in its own.
     */
    public function validate(SalesDocument $document, User|string $by): void
    {
        if ([] !== $this->validationRefusals($document)) {
            throw new \LogicException('This document may not be validated now.');
        }
        $this->entityManager->wrapInTransaction(function () use ($document, $by): void {
            $dossier = $document->getDossier();
            $type = $document->getType();
            $year = (int) $document->getDate()->format('Y');
            $sequence = $this->sequences->findLocked($dossier, $type, $year);
            if (null === $sequence) {
                $sequence = new SalesSequence($dossier, $type, $year);
                $this->entityManager->persist($sequence);
            }
            $number = SalesSequence::label($type, $year, $sequence->take($document->getDate()));
            $customer = $document->getCustomer();
            $dueDate = $document->getDueDate();
            if (null === $dueDate && SalesDocumentType::Invoice === $type) {
                $dueDate = $customer->getPaymentTerms()->dueDate($document->getDate());
            }
            $document->markValidated($number, self::print($customer), $dueDate, $this->deposits($document));
            $document->recordHistory('validated', $dossier->getDossierDate(), self::name($by), \sprintf('Validation sous le numéro %s', $number));
            $this->auditTrail->inDossier($dossier, AuditAction::SalesDocumentValidated, $by, \sprintf('Validation de %s %s', mb_strtolower($type->label()), $number), ['document' => $document->getId(), 'number' => $number]);
            $this->entityManager->flush();
        });
        $this->posting->validated($document, $by);
    }

    /** Why it may not be turned into that type; null when it may. */
    public function transformRefusal(SalesDocument $document, SalesDocumentType $into): ?string
    {
        if ($document->isDraft()) {
            return 'Un brouillon se valide avant d’être transformé.';
        }
        if (!\in_array($into, $document->getType()->transformsInto(), true)) {
            return \sprintf('%s ne se transforme pas en %s.', $document->getType()->label(), mb_strtolower($into->label()));
        }
        if (SalesDocumentType::Deposit === $into) {
            foreach ($this->chain($document) as $link) {
                if (SalesDocumentType::Invoice === $link->getType()) {
                    return \sprintf('La commande %s a sa facture : %s. Un acompte se facture avant elle.', $document->getNumber() ?? '', $link->getTitle());
                }
            }

            return null;
        }
        if (SalesDocumentType::CreditNote !== $into) {
            foreach ($this->documents->findMadeFrom($document) as $made) {
                if (SalesDocumentType::Deposit !== $made->getType()) {
                    return \sprintf('%s a déjà sa suite : %s.', $document->getNumber() ?? '', $made->getTitle());
                }
            }
        }

        return null;
    }

    /**
     * The next document of the chain, a draft carrying the same customer and lines, dated the
     * dossier's date - its cash discount and VAT on receipt with it. A deposit invoice takes
     * `percent` of each VAT rate's base of the order instead, one line per rate.
     */
    public function transform(SalesDocument $document, SalesDocumentType $into, User|string $by, ?BigDecimal $percent = null): SalesDocument
    {
        if (null !== $this->transformRefusal($document, $into)) {
            throw new \LogicException('This document may not be turned into that type.');
        }
        $dossier = $document->getDossier();
        $next = new SalesDocument($dossier, $into, $document->getCustomer(), $dossier->getDossierDate(), $document);
        $lines = SalesDocumentType::Deposit === $into
            ? self::depositLines($document, $percent ?? throw new \LogicException('A deposit takes a percentage of the order.'))
            : $document->getLines();
        $next->rewrite($document->getCustomer(), $dossier->getDossierDate(), null, $document->getReference(), $lines, $into->takesCashDiscount() ? $document->getCashDiscount() : null, $document->isVatOnReceipts());
        $next->recordHistory('created', $dossier->getDossierDate(), self::name($by), \sprintf('Brouillon ouvert depuis %s', $document->getNumber() ?? ''));
        $document->recordHistory('transformed', $dossier->getDossierDate(), self::name($by), \sprintf('Transformation en %s', mb_strtolower($into->label())));
        $this->entityManager->persist($next);
        $this->auditTrail->inDossier($dossier, AuditAction::SalesDocumentCreated, $by, \sprintf('Brouillon de %s ouvert depuis %s', mb_strtolower($into->label()), $document->getNumber() ?? ''), ['type' => $into->value, 'source' => $document->getId()]);

        return $next;
    }

    /** Sent to the customer - simulated: nothing leaves the application, the history says it went. */
    public function send(SalesDocument $document, User|string $by): void
    {
        $dossier = $document->getDossier();
        $document->markSent($dossier->getDossierDate());
        $customer = $document->getCustomer();
        $to = null === $customer->getEmail() ? $customer->getName() : \sprintf('%s (%s)', $customer->getName(), $customer->getEmail());
        $document->recordHistory('sent', $dossier->getDossierDate(), self::name($by), \sprintf('Envoi à %s', $to));
        $this->auditTrail->inDossier($dossier, AuditAction::SalesDocumentSent, $by, \sprintf('Envoi de %s à %s', $document->getNumber() ?? '', $customer->getName()), ['document' => $document->getId()]);
    }

    /** @return CustomerPrint */
    public static function print(ThirdParty $customer): array
    {
        $lines = array_values(array_filter([
            $customer->getStreet(),
            $customer->getStreet2(),
            trim(($customer->getPostalCode() ?? '').' '.($customer->getCity() ?? '')),
            'FR' === $customer->getCountry() ? null : $customer->getCountry(),
        ], static fn (?string $line): bool => null !== $line && '' !== $line));

        return ['name' => $customer->getName(), 'lines' => $lines, 'siren' => $customer->getSiren(), 'vatNumber' => $customer->getVatNumber()];
    }

    /**
     * The deposit invoices made from the documents of its chain before it (the quote, the order,
     * the delivery note), oldest first.
     *
     * @return list<SalesDocument>
     */
    private function depositsBefore(SalesDocument $document): array
    {
        $deposits = [];
        for ($source = $document->getSource(); null !== $source; $source = $source->getSource()) {
            foreach ($this->documents->findMadeFrom($source) as $made) {
                if (SalesDocumentType::Deposit === $made->getType()) {
                    $deposits[] = $made;
                }
            }
        }
        usort($deposits, static fn (SalesDocument $a, SalesDocument $b): int => $a->getId() <=> $b->getId());

        return $deposits;
    }

    /**
     * A deposit invoice's lines: `percent` of each VAT rate's base of the order, half up.
     *
     * @return list<SalesLine>
     */
    public static function depositLines(SalesDocument $order, BigDecimal $percent): array
    {
        $lines = [];
        foreach ($order->getTotals()->vatLines as $vat) {
            $amount = $vat->base->times($percent->dividedBy(100, 6), RoundingMode::HalfUp);
            $lines[] = new SalesLine(
                null,
                \sprintf('Acompte de %s %% sur %s %s', str_replace('.', ',', (string) $percent->strippedOfTrailingZeros()), mb_strtolower($order->getType()->label()), $order->getNumber() ?? ''),
                '',
                BigDecimal::one(),
                $amount,
                null,
                $vat->rate,
            );
        }

        return $lines;
    }

    /** @return array{0: ?string, 1: bool} the cash discount and the VAT on receipt a type keeps */
    private static function flags(SalesDocumentType $type, SalesDocumentFields $fields): array
    {
        return [$type->takesCashDiscount() ? $fields->cashDiscount : null, $type->isInvoicing() && $fields->vatOnReceipts];
    }

    private function customer(Dossier $dossier, string $code): ThirdParty
    {
        return $this->thirdParties->findOneByCode($dossier, $code) ?? throw new \LogicException('A checked document names a customer of the dossier.');
    }

    private static function name(User|string $by): string
    {
        return $by instanceof User ? $by->getDisplayName() : $by;
    }
}
