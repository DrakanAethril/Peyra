<?php

declare(strict_types=1);

namespace App\Tests\Service\Sales;

use App\Entity\Dossier\Dossier;
use App\Entity\Sales\SalesDocument;
use App\Enum\Sales\SalesDocumentType;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Service\Sales\SalesDocumentDraft;
use App\Service\Sales\SalesDocuments;
use App\Service\ThirdParty\ThirdPartySheet;
use App\Tests\Support\BuildsLedger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The commercial documents through their door (step 2.3), in BuildsLedger's dossier (date
 * 15/03/2026, customer C0019 on thirty days): the numbering is continuous and follows the dates, a
 * validated document never changes, the chain moves on once, a credit note never credits more than
 * its invoice, and « Envoyer » only writes the history.
 */
final class SalesDocumentsTest extends KernelTestCase
{
    use BuildsLedger;

    public function testTheNumberingIsContinuousAndWithoutAGap(): void
    {
        $dossier = $this->ledgerDossier();
        $documents = self::getContainer()->get(SalesDocuments::class);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $abandoned = $this->draft($dossier, SalesDocumentType::Invoice, '2026-03-12', '2');
        $documents->delete($abandoned, 'test');
        $entityManager->flush();

        $first = $this->draft($dossier, SalesDocumentType::Invoice, '2026-03-12', '2');
        $documents->validate($first, 'test');
        self::assertSame('FV-2026-0001', $first->getNumber(), 'A deleted draft took no number.');
        self::assertSame('2026-04-11', $first->getDueDate()?->format('Y-m-d'), 'Thirty days from the invoice’s date, the customer’s terms.');
        self::assertSame('Girard, Jean et Anne', $first->getCustomerPrint()['name'] ?? null);

        $earlier = $this->draft($dossier, SalesDocumentType::Invoice, '2026-03-10', '1');
        self::assertSame(['La numérotation suit l’ordre des dates : le dernier numéro de ce type est daté du 12/03/2026.'], $documents->validationRefusals($earlier));
        $later = $this->draft($dossier, SalesDocumentType::Invoice, '2026-03-16', '1');
        self::assertSame(['Le document est daté après la date du dossier (15/03/2026).'], $documents->validationRefusals($later));

        $second = $this->draft($dossier, SalesDocumentType::Invoice, '2026-03-15', '1');
        $documents->validate($second, 'test');
        self::assertSame('FV-2026-0002', $second->getNumber());

        $this->expectException(\LogicException::class);
        $second->rewrite($second->getCustomer(), $second->getDate(), null, 'changée', []);
    }

    public function testTheChainMovesOnOnceAndACreditNoteNeverCreditsMore(): void
    {
        $dossier = $this->ledgerDossier();
        $documents = self::getContainer()->get(SalesDocuments::class);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $quote = $this->draft($dossier, SalesDocumentType::Quote, '2026-03-02', '2');
        self::assertSame('Un brouillon se valide avant d’être transformé.', $documents->transformRefusal($quote, SalesDocumentType::Order));
        $documents->validate($quote, 'test');
        self::assertSame('DE-2026-0001', $quote->getNumber());
        self::assertSame('Devis ne se transforme pas en avoir.', $documents->transformRefusal($quote, SalesDocumentType::CreditNote));

        $order = $documents->transform($quote, SalesDocumentType::Order, 'test');
        $entityManager->flush();
        self::assertTrue($order->isDraft());
        self::assertSame($quote, $order->getSource());
        self::assertSame('2026-03-15', $order->getDate()->format('Y-m-d'), 'Dated the dossier’s date.');
        self::assertSame(['created', 'validated', 'transformed'], array_column($quote->getHistory(), 'event'));
        self::assertSame('DE-2026-0001 a déjà sa suite : Commande (brouillon).', $documents->transformRefusal($quote, SalesDocumentType::Invoice));
        self::assertSame([$quote, $order], $documents->chain($order));

        $invoice = $this->draft($dossier, SalesDocumentType::Invoice, '2026-03-15', '2');
        $documents->validate($invoice, 'test');
        $credit = $documents->transform($invoice, SalesDocumentType::CreditNote, 'test');
        $entityManager->flush();
        self::assertSame([], $documents->validationRefusals($credit));
        $documents->validate($credit, 'test');
        self::assertSame('AV-2026-0001', $credit->getNumber());
        self::assertSame($invoice->getTotals()->includingTax->cents, $credit->getTotals()->includingTax->cents);

        $again = $documents->transform($invoice, SalesDocumentType::CreditNote, 'test');
        $entityManager->flush();
        self::assertSame(['Les avoirs de la facture FV-2026-0001 dépasseraient son montant (240,00 TTC).'], $documents->validationRefusals($again));
        self::assertSame([$invoice, $credit, $again], $documents->chain($invoice));

        $documents->send($invoice, 'test');
        $entityManager->flush();
        self::assertSame('2026-03-15', $invoice->getSentOn()?->format('Y-m-d'));
        self::assertSame('Envoi à Girard, Jean et Anne', $invoice->getHistory()[\count($invoice->getHistory()) - 1]['detail']);

        $customer = self::getContainer()->get(ThirdPartyRepository::class)->findOneByCode($dossier, 'C0019') ?? throw new \LogicException('C0019');
        self::assertSame('Ce tiers est le client de 5 documents commerciaux : il reste au dossier.', self::getContainer()->get(ThirdPartySheet::class)->deletionRefusal($customer));
    }

    /** A draft for C0019: n × 100,00 at 20 %. */
    private function draft(Dossier $dossier, SalesDocumentType $type, string $date, string $quantity): SalesDocument
    {
        $documents = self::getContainer()->get(SalesDocuments::class);
        $draft = new SalesDocumentDraft();
        $draft->customer = 'C0019';
        $draft->date = $date;
        $draft->readLines([['article' => '', 'designation' => 'Prestation', 'unit' => 'heure', 'quantity' => $quantity, 'unitPrice' => '100,00', 'discount' => '', 'rate' => '20']]);
        $fields = $documents->check($draft, $dossier, $type) ?? throw new \LogicException(implode(' ', $draft->errors));
        $document = $documents->create($dossier, $type, $fields, 'test');
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return $document;
    }
}
