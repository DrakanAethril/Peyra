<?php

declare(strict_types=1);

namespace App\Tests\Service\Sales;

use App\Entity\Accounting\Entry;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\Sales\SalesDocument;
use App\Enum\Accounting\AccountingBehaviour;
use App\Enum\Accounting\AccountingModule;
use App\Enum\Accounting\EntryOrigin;
use App\Enum\Accounting\EntryState;
use App\Enum\Identity\AccountKind;
use App\Enum\Identity\EntryBasis;
use App\Enum\Sales\SalesDocumentType;
use App\Repository\Accounting\EntryRepository;
use App\Repository\Accounting\JournalRepository;
use App\Repository\Inventory\ArticleRepository;
use App\Repository\Sales\SalesDocumentRepository;
use App\Repository\Sales\SalesSequenceRepository;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Service\Accounting\Gateway\AccountingBehaviours;
use App\Service\Accounting\Gateway\AccountingGateway;
use App\Service\Accounting\Ledger\Ledger;
use App\Service\Audit\AuditTrail;
use App\Service\Document\Processing\ProposedEntryCheck;
use App\Service\Inventory\Stock\StockMovements;
use App\Service\Purchasing\Receipts;
use App\Service\Regulatory\RegulatoryReader;
use App\Service\Sales\SalesDocumentDraft;
use App\Service\Sales\SalesDocuments;
use App\Service\Sales\SalesPosting;
use App\Tests\Support\BuildsLedger;
use Brick\Math\BigDecimal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * An invoicing document reaches the books through AccountingGateway alone (step 2.3b), under each
 * behaviour a mission may choose - « Comptabiliser » writes its entry at the validation,
 * « Proposer » when the student passes it, « Laisser saisir » never -, and a final invoice deducts
 * its order's deposits. BuildsLedger's dossier (15/03/2026, Girard C0019 on 411GIRA, thirty days).
 */
final class SalesPostingTest extends KernelTestCase
{
    use BuildsLedger;

    private ?User $user = null;

    /** 2 × 100,00 at 20 % on a free line: 411GIRA 240,00 / 706000 200,00, 445710 40,00. */
    public function testPostWritesTheEntryAtTheValidation(): void
    {
        $dossier = $this->ledgerDossier();
        [$documents] = $this->doors(AccountingBehaviour::Post);
        $invoice = $this->draft($documents, $dossier, SalesDocumentType::Invoice, '2026-03-12', '2');

        $documents->validate($invoice, 'test');

        $entry = $invoice->getEntry();
        self::assertNotNull($entry);
        self::assertSame([EntryState::Draft, EntryOrigin::Proposal, AccountingModule::Sales->value, 'FV-2026-0001', 'VTE'], [$entry->getState(), $entry->getOrigin(), $entry->getSource(), $entry->getDocument(), $entry->getJournal()->getCode()]);
        self::assertSame([['411000', 24_000, 0], ['706000', 0, 20_000], ['445710', 0, 4_000]], self::lines($entry));
        self::assertSame('2026-04-11', $entry->getLines()[0]->getDueDate()?->format('Y-m-d'), 'The invoice’s due date on the customer’s line.');
        self::assertSame('posted', $invoice->getHistory()[\count($invoice->getHistory()) - 1]['event']);
    }

    public function testProposeWaitsForTheStudentToPassIt(): void
    {
        $dossier = $this->ledgerDossier();
        [$documents, $posting] = $this->doors(AccountingBehaviour::Propose);
        $invoice = $this->draft($documents, $dossier, SalesDocumentType::Invoice, '2026-03-12', '1', vatOnReceipts: true);

        $documents->validate($invoice, 'test');
        self::assertNull($invoice->getEntry(), 'Proposer writes nothing at the validation.');
        $proposal = $posting->proposal($invoice);
        self::assertNotNull($proposal);
        self::assertTrue($posting->check($invoice, $proposal)->isWritable());

        $check = $posting->post($invoice, $this->user());
        self::assertTrue($check->posted, implode(' ', $check->errors));
        $entry = $posting->entries($invoice)[0] ?? throw new \LogicException('The entry passed.');
        self::assertSame($entry, $invoice->getEntry());
        self::assertSame(EntryOrigin::Proposal, $entry->getOrigin());
        self::assertSame([['411000', 12_000, 0], ['706000', 0, 10_000], ['445800', 0, 2_000]], self::lines($entry), 'VAT on receipt: 445800.');

        $this->expectException(\LogicException::class);
        $posting->post($invoice, $this->user());
    }

    public function testLetEnterShowsNothingAndReadsWhatTheStudentEntered(): void
    {
        $dossier = $this->ledgerDossier();
        [$documents, $posting] = $this->doors(AccountingBehaviour::LetEnter);
        $invoice = $this->draft($documents, $dossier, SalesDocumentType::Invoice, '2026-03-12', '1');

        $documents->validate($invoice, 'test');
        self::assertNull($invoice->getEntry());
        self::assertSame([], $posting->entries($invoice));

        $typed = $this->entry($dossier, 'VTE', '2026-03-12', 'FV-2026-0001', ['411000/C0019 D 120.00', '706000 C 100.00', '445710 C 20.00']);
        self::assertSame([$typed], $posting->entries($invoice), 'The entries of its number.');

        $this->expectException(\LogicException::class);
        $posting->post($invoice, $this->user());
    }

    /**
     * An order of 10 × 100,00 at 20 % (1 200,00 TTC), a deposit of 30 % (300,00 + 60,00), then the
     * final invoice: 1 200,00 less 360,00 = 840,00 due. Its entry: 411000 840,00, 419100 300,00,
     * 445710 60,00 / 706000 1 000,00, 445710 200,00.
     */
    public function testAFinalInvoiceDeductsTheDepositsOfItsOrder(): void
    {
        $dossier = $this->ledgerDossier();
        [$documents] = $this->doors(AccountingBehaviour::Post);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $order = $this->draft($documents, $dossier, SalesDocumentType::Order, '2026-03-02', '10');
        $documents->validate($order, 'test');
        self::assertNull($order->getEntry(), 'An order goes to no book.');

        $deposit = $documents->transform($order, SalesDocumentType::Deposit, 'test', BigDecimal::of('30'));
        $entityManager->flush();
        self::assertSame(['Acompte de 30 % sur commande BC-2026-0001', 30_000, '20'], [$deposit->getLines()[0]->designation, $deposit->getLines()[0]->unitPrice->cents, $deposit->getLines()[0]->rate]);

        $invoice = $documents->transform($order, SalesDocumentType::Invoice, 'test');
        $entityManager->flush();
        self::assertSame(['Une facture d’acompte de la commande est encore en brouillon : validez-la ou supprimez-la avant la facture finale.'], $documents->validationRefusals($invoice));

        $documents->validate($deposit, 'test');
        self::assertSame('AC-2026-0001', $deposit->getNumber());
        $depositEntry = $deposit->getEntry() ?? throw new \LogicException('The deposit’s entry.');
        self::assertSame([['411000', 36_000, 0], ['419100', 0, 30_000], ['445710', 0, 6_000]], self::lines($depositEntry));

        self::assertSame(84_000, $documents->totals($invoice)->amountDue->cents);
        $documents->validate($invoice, 'test');
        self::assertSame(['AC-2026-0001'], array_map(static fn ($d): string => $d->number, $invoice->getDeposits() ?? []), 'Frozen at the validation.');
        $entry = $invoice->getEntry() ?? throw new \LogicException('The invoice’s entry.');
        self::assertSame([['411000', 84_000, 0], ['419100', 30_000, 0], ['445710', 6_000, 0], ['706000', 0, 100_000], ['445710', 0, 20_000]], self::lines($entry));
        self::assertSame('La commande BC-2026-0001 a sa facture : FV-2026-0001. Un acompte se facture avant elle.', $documents->transformRefusal($order, SalesDocumentType::Deposit));
    }

    /** A cash discount of 2 % on 1 000,00: 411000 1 176,00, 665000 20,00 / 706000 1 000,00, 445710 196,00. */
    public function testACashDiscountGoesTo665(): void
    {
        $dossier = $this->ledgerDossier();
        [$documents] = $this->doors(AccountingBehaviour::Post);
        $invoice = $this->draft($documents, $dossier, SalesDocumentType::Invoice, '2026-03-12', '10', cashDiscount: '2');

        $documents->validate($invoice, 'test');

        self::assertSame('2', $invoice->getCashDiscount());
        self::assertSame([['411000', 117_600, 0], ['665000', 2_000, 0], ['706000', 0, 100_000], ['445710', 0, 19_600]], self::lines($invoice->getEntry() ?? throw new \LogicException('entry')));
    }

    /** A cash discount and VAT on receipt are kept only by the types that take them. */
    public function testAQuoteKeepsNeitherFlag(): void
    {
        $dossier = $this->ledgerDossier();
        [$documents] = $this->doors(AccountingBehaviour::Post);
        $quote = $this->draft($documents, $dossier, SalesDocumentType::Quote, '2026-03-12', '1', cashDiscount: '2', vatOnReceipts: true);

        self::assertSame([null, false], [$quote->getCashDiscount(), $quote->isVatOnReceipts()]);
    }

    /** @return array{SalesDocuments, SalesPosting} the doors, the gateway answering one behaviour */
    private function doors(AccountingBehaviour $behaviour): array
    {
        $container = self::getContainer();
        $gateway = new AccountingGateway($container->get(Ledger::class), new readonly class($behaviour) implements AccountingBehaviours {
            public function __construct(private AccountingBehaviour $behaviour)
            {
            }

            public function for(Dossier $dossier, AccountingModule $module): AccountingBehaviour
            {
                return $this->behaviour;
            }
        });
        $posting = new SalesPosting(
            $gateway,
            $container->get(JournalRepository::class),
            $container->get(ArticleRepository::class),
            $container->get(EntryRepository::class),
            $container->get(ProposedEntryCheck::class),
            $container->get(AuditTrail::class),
            $container->get(EntityManagerInterface::class),
        );
        $documents = new SalesDocuments(
            $container->get(EntityManagerInterface::class),
            $container->get(SalesDocumentRepository::class),
            $container->get(SalesSequenceRepository::class),
            $container->get(ThirdPartyRepository::class),
            $container->get(ArticleRepository::class),
            $container->get(AuditTrail::class),
            $container->get(RegulatoryReader::class),
            $posting,
            $container->get(Receipts::class),
            $container->get(StockMovements::class),
        );

        return [$documents, $posting];
    }

    /** A draft for C0019: n × 100,00 at 20 % on a free line. */
    private function draft(SalesDocuments $documents, Dossier $dossier, SalesDocumentType $type, string $date, string $quantity, string $cashDiscount = '', bool $vatOnReceipts = false): SalesDocument
    {
        $draft = new SalesDocumentDraft();
        $draft->customer = 'C0019';
        $draft->date = $date;
        $draft->cashDiscount = $cashDiscount;
        $draft->vatOnReceipts = $vatOnReceipts;
        $draft->readLines([['article' => '', 'designation' => 'Prestation', 'unit' => 'heure', 'quantity' => $quantity, 'unitPrice' => '100,00', 'discount' => '', 'rate' => '20']]);
        $fields = $documents->check($draft, $dossier, $type) ?? throw new \LogicException(implode(' ', $draft->errors));
        $document = $documents->create($dossier, $type, $fields, 'test');
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return $document;
    }

    /** @return list<array{string, int, int}> */
    private static function lines(Entry $entry): array
    {
        $rows = [];
        foreach ($entry->getLines() as $line) {
            $rows[] = [$line->getAccount()->getNumber(), $line->getDebit()->cents, $line->getCredit()->cents];
        }

        return $rows;
    }

    private function user(): User
    {
        if (null !== $this->user) {
            return $this->user;
        }
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User('uidNumber:9002', 'eleve.ventes', 'Élève Ventes', AccountKind::Student, EntryBasis::StudentGroup, ['student', 'cg-1'], new \DateTimeImmutable('2026-01-01'));
        $entityManager->persist($user);
        $entityManager->flush();

        return $this->user = $user;
    }
}
