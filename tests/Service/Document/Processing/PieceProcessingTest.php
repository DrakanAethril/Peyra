<?php

declare(strict_types=1);

namespace App\Tests\Service\Document\Processing;

use App\Entity\Document\Document;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Accounting\AccountingBehaviour;
use App\Enum\Accounting\AccountingModule;
use App\Enum\Accounting\EntryOrigin;
use App\Enum\Accounting\EntryState;
use App\Enum\Document\AnomalyNature;
use App\Enum\Document\AnomalyOutcome;
use App\Enum\Document\DocumentState;
use App\Enum\Document\DocumentType;
use App\Enum\Identity\AccountKind;
use App\Enum\Identity\EntryBasis;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Repository\Accounting\EntryLineRepository;
use App\Repository\Accounting\EntryRepository;
use App\Repository\Accounting\JournalRepository;
use App\Repository\Dossier\PeriodRepository;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Service\Accounting\Entry\AssistanceLevels;
use App\Service\Accounting\Entry\GridLine;
use App\Service\Accounting\Entry\JournalGrid;
use App\Service\Accounting\Gateway\AccountingBehaviours;
use App\Service\Accounting\Gateway\AccountingGateway;
use App\Service\Accounting\Ledger\Ledger;
use App\Service\Audit\AuditTrail;
use App\Service\Document\PieceAnomaly;
use App\Service\Document\Processing\PieceProcessing;
use App\Service\Document\Processing\PieceProposal;
use App\Service\Document\VatLine;
use App\Service\Shared\Money;
use App\Tests\Support\BuildsLedger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * PieceProcessing (step 2.2a) under each behaviour a mission may choose: « Proposer » keeps the
 * proposal on the pièce and writes nothing before F10; « Laisser saisir » shows nothing and writes
 * what the student typed as their own entry; « Comptabiliser » puts a whole proposal in the
 * brouillard on arrival. The Scierie's invoice FA-2603-0112 of 2 707,00 HT, 541,40 VAT, 3 248,40
 * TTC; the supplier's last entry used 607000, which the proposal takes.
 */
final class PieceProcessingTest extends KernelTestCase
{
    use BuildsLedger;

    public function testProposeKeepsTheProposalAndF10WritesWhatTheStudentMadeOfIt(): void
    {
        $dossier = $this->dossierWithHistory();
        $processing = $this->processing(AccountingBehaviour::Propose);
        $document = $this->invoice($dossier);

        $processing->arrived($document, 'test');
        $proposal = $processing->proposal($document);
        self::assertNotNull($proposal);
        self::assertSame([['607000', 270_700, 0], ['445660', 54_140, 0], ['401SCIE', 0, 324_840]], array_map(static fn (GridLine $l): array => [$l->account, $l->debit, $l->credit], $proposal->lines));
        self::assertSame(DocumentState::ToProcess, $document->getState(), 'Proposer n’écrit rien avant F10.');
        self::assertFalse($processing->isStale($document));

        // The student corrects the expense account: 601000, and posts.
        $corrected = new PieceProposal($proposal->journal, $proposal->date, $proposal->document, $proposal->label, [new GridLine('601000', '', '', 270_700), ...\array_slice($proposal->lines, 1)]);
        $check = $processing->post($document, $corrected, $this->user());
        self::assertTrue($check->posted, implode(' ', $check->errors));
        $entry = $document->getEntry();
        self::assertNotNull($entry);
        self::assertSame(DocumentState::Posted, $document->getState());
        self::assertSame([EntryState::Draft, EntryOrigin::Proposal, 'documents', 'FA-2603-0112'], [$entry->getState(), $entry->getOrigin(), $entry->getSource(), $entry->getDocument()]);
        self::assertSame('601000', $entry->getLines()[0]->getAccount()->getNumber());
        self::assertSame('2026-04-11', $entry->getLines()[2]->getDueDate()?->format('Y-m-d'), 'L’échéance lue va sur la ligne du fournisseur.');
    }

    public function testAnUnbalancedEntryWritesNothing(): void
    {
        $dossier = $this->dossierWithHistory();
        $processing = $this->processing(AccountingBehaviour::Propose);
        $document = $this->invoice($dossier);
        $processing->arrived($document, 'test');
        $proposal = $processing->proposal($document);
        self::assertNotNull($proposal);

        $wrong = new PieceProposal($proposal->journal, $proposal->date, $proposal->document, $proposal->label, [new GridLine('601000', '', '', 270_700), new GridLine('401SCIE', '', '', 0, 324_840)]);
        $check = $processing->post($document, $wrong, $this->user());

        self::assertFalse($check->posted);
        self::assertStringStartsWith('L’écriture n’est pas équilibrée', (string) $check->error('lines'));
        self::assertSame(DocumentState::ToProcess, $document->getState());
        self::assertSame(1, static::getContainer()->get(EntryRepository::class)->count([]), 'Seule l’écriture de l’historique existe.');
    }

    public function testLetEnterWritesTheStudentsOwnEntry(): void
    {
        $dossier = $this->dossierWithHistory();
        $processing = $this->processing(AccountingBehaviour::LetEnter);
        $document = $this->invoice($dossier);
        $processing->arrived($document, 'test');
        self::assertNotNull($processing->proposal($document), 'La proposition est gardée pour le corrigé.');

        $typed = new PieceProposal('ACH', '2026-03-12', 'FA-2603-0112', 'Scierie FA 0112', [new GridLine('601000', '', '', 270_700), new GridLine('445660', '', '', 54_140), new GridLine('401SCIE', '', '', 0, 324_840)]);
        self::assertTrue($processing->post($document, $typed, $this->user())->posted);
        self::assertSame(EntryOrigin::Manual, $document->getEntry()?->getOrigin());
    }

    public function testPostWritesAWholeProposalOnArrival(): void
    {
        $dossier = $this->dossierWithHistory();
        $processing = $this->processing(AccountingBehaviour::Post);

        $document = $this->invoice($dossier);
        $processing->arrived($document, 'test');
        self::assertSame(DocumentState::Posted, $document->getState());
        self::assertSame([EntryState::Draft, EntryOrigin::Proposal], [$document->getEntry()?->getState(), $document->getEntry()?->getOrigin()], 'Au brouillard, sans intervention.');

        // A supplier without history: the expense has no account, the pièce waits for the student.
        $other = $this->invoice($dossier, 'F0009');
        $processing->arrived($other, 'test');
        self::assertSame(DocumentState::ToProcess, $other->getState());
        self::assertSame('', $processing->proposal($other)?->lines[0]->account);
    }

    public function testAChangedReadingIsStaleUntilTheEntryIsTakenAgain(): void
    {
        $dossier = $this->dossierWithHistory();
        $processing = $this->processing(AccountingBehaviour::Propose);
        $document = $this->invoice($dossier);
        $processing->arrived($document, 'test');

        $document->read(DocumentType::PurchaseInvoice, $document->getThirdParty(), 'FA-2603-0112', $document->getDocumentDate(), $document->getDueDate(), null, [new VatLine('20', Money::cents(270_000), Money::cents(54_000))], Money::cents(270_000), Money::cents(54_000), Money::cents(324_000));
        self::assertTrue($processing->isStale($document));
        self::assertSame(324_840, $processing->proposal($document)?->totalCredit(), 'Changer les données lues ne recalcule pas l’écriture.');

        $processing->rebuild($document, $this->user());
        self::assertFalse($processing->isStale($document));
        self::assertSame(324_000, $processing->proposal($document)?->totalCredit());
        self::assertSame(DocumentState::InProgress, $document->getState());
    }

    public function testARejectedPieceIsSetAsideWithItsReason(): void
    {
        $dossier = $this->dossierWithHistory();
        $processing = $this->processing(AccountingBehaviour::Propose);
        $document = $this->invoice($dossier);

        $processing->reportAnomaly($document, new PieceAnomaly(AnomalyNature::MissingMention, 'Pas de numéro de TVA.', AnomalyOutcome::PostWithReserve, new \DateTimeImmutable('2026-03-15')), $this->user());
        self::assertSame(DocumentState::InProgress, $document->getState());
        self::assertTrue($document->isPostedWithReserve());

        $processing->reportAnomaly($document, new PieceAnomaly(AnomalyNature::Duplicate, 'Déjà reçue le 12/03.', AnomalyOutcome::Reject, new \DateTimeImmutable('2026-03-15')), $this->user());
        self::assertSame(DocumentState::Discarded, $document->getState());
        self::assertSame('Déjà reçue le 12/03.', $document->getRejection()?->comment);
        self::assertCount(2, $document->getAnomalies());
    }

    /** The ledger's dossier, a supplier with no history (F0009), and one purchase of the Scierie on 607000. */
    private function dossierWithHistory(): Dossier
    {
        $dossier = $this->ledgerDossier();
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $scierie = $container->get(ThirdPartyRepository::class)->findOneByCode($dossier, 'F0001');
        \assert(null !== $scierie && null !== $scierie->getAccount());
        $other = new ThirdParty($dossier, 'F0009', ThirdPartyKind::Supplier, 'Quincaillerie Faure');
        $other->keepOn($scierie->getAccount(), '401FAUR');
        $entityManager->persist($other);
        $entityManager->flush();
        $this->entry($dossier, 'ACH', '2026-01-08', 'FA-2601-0012', ['607000 D 3465.10', '445660 D 693.02', '401000/F0001 C 4158.12'], true, 'Facture Scierie');

        return $dossier;
    }

    private function invoice(Dossier $dossier, string $supplier = 'F0001'): Document
    {
        $container = static::getContainer();
        $document = new Document($dossier, DocumentType::PurchaseInvoice, 'dossiers/'.$dossier->getId().'/pieces/test.pdf', 'FA-2603-0112.pdf', 'application/pdf', new \DateTimeImmutable('2026-03-15'), null);
        $document->read(
            DocumentType::PurchaseInvoice,
            $container->get(ThirdPartyRepository::class)->findOneByCode($dossier, $supplier),
            'FA-2603-0112',
            new \DateTimeImmutable('2026-03-12'),
            new \DateTimeImmutable('2026-04-11'),
            'CF-0098',
            [new VatLine('20', Money::cents(270_700), Money::cents(54_140))],
            Money::cents(270_700),
            Money::cents(54_140),
            Money::cents(324_840),
        );
        $container->get(EntityManagerInterface::class)->persist($document);

        return $document;
    }

    private ?User $user = null;

    private function user(): User
    {
        if (null !== $this->user) {
            return $this->user;
        }
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('uidNumber:9001', 'eleve.test', 'Élève Test', AccountKind::Student, EntryBasis::StudentGroup, ['student', 'cg-1'], new \DateTimeImmutable('2026-01-01'));
        $entityManager->persist($user);
        $entityManager->flush();

        return $this->user = $user;
    }

    private function processing(AccountingBehaviour $behaviour): PieceProcessing
    {
        $container = static::getContainer();
        $gateway = new AccountingGateway($container->get(Ledger::class), new readonly class($behaviour) implements AccountingBehaviours {
            public function __construct(private AccountingBehaviour $behaviour)
            {
            }

            public function for(Dossier $dossier, AccountingModule $module): AccountingBehaviour
            {
                return $this->behaviour;
            }
        });

        return new PieceProcessing(
            $gateway,
            $container->get(JournalRepository::class),
            $container->get(PeriodRepository::class),
            $container->get(EntryLineRepository::class),
            $container->get(JournalGrid::class),
            $container->get(AssistanceLevels::class),
            $container->get(AuditTrail::class),
        );
    }
}
