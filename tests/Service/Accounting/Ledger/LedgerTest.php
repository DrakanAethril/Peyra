<?php

declare(strict_types=1);

namespace App\Tests\Service\Accounting\Ledger;

use App\Doctrine\LedgerRefused;
use App\Entity\Accounting\Entry;
use App\Entity\Accounting\EntryLine;
use App\Entity\Audit\AuditEvent;
use App\Enum\Accounting\EntryOrigin;
use App\Enum\Accounting\EntryState;
use App\Enum\Audit\AuditAction;
use App\Repository\Accounting\EntryRepository;
use App\Repository\Accounting\JournalRepository;
use App\Repository\Dossier\PeriodRepository;
use App\Service\Accounting\Ledger\EntryDraft;
use App\Service\Accounting\Ledger\EntryLineDraft;
use App\Service\Accounting\Ledger\EntryRefused;
use App\Service\Accounting\Ledger\Ledger;
use App\Service\Dossier\Exercises;
use App\Service\Shared\Money;
use App\Tests\Support\BuildsLedger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The Ledger, the one door of the entries (conception § 5): the brouillard, the validation and its
 * numbering without a gap per journal and per exercise, the reversal, the closed period - and
 * LedgerGuard, which refuses at flush what went around it.
 */
final class LedgerTest extends KernelTestCase
{
    use BuildsLedger;

    private const array PURCHASE = ['607000 D 3465.10', '445660 D 693.02', '401000/F0001 C 4158.12'];

    public function testADraftIsWrittenBalancedUnnumberedAndRecorded(): void
    {
        $dossier = $this->ledgerDossier();
        $entry = $this->entry($dossier, 'ACH', '2026-01-08', 'FA-2601-0012', self::PURCHASE);

        self::assertSame(EntryState::Draft, $entry->getState());
        self::assertNull($entry->getNumber());
        self::assertSame(EntryOrigin::Manual, $entry->getOrigin());
        self::assertSame('Janvier 2026', $entry->getPeriod()->getLabel());
        self::assertSame('2026', $entry->getExercise()->getCode());
        self::assertSame([415_812, 415_812], [$entry->totalDebit()->cents, $entry->totalCredit()->cents]);
        self::assertSame([1, 2, 3], array_map(static fn (EntryLine $l): int => $l->getPosition(), $entry->getLines()));
        self::assertSame('F0001', $entry->getLines()[2]->getThirdParty()?->getCode());

        $audit = $this->audit(AuditAction::EntryDrafted);
        self::assertCount(1, $audit);
        self::assertSame("Écriture enregistrée au brouillard : ACH · 08/01/2026 · FA-2601-0012 · 4\u{202F}158,12", $audit[0]->getSummary());
    }

    public function testARefusedEntryWritesNothing(): void
    {
        $dossier = $this->ledgerDossier();
        try {
            $this->entry($dossier, 'ACH', '2026-01-08', 'FA-2601-0012', ['607000 D 3465.10', '401000/F0001 C 3465.00']);
            self::fail('Une écriture déséquilibrée est refusée.');
        } catch (EntryRefused $refused) {
            self::assertSame('L’écriture n’est pas équilibrée : écart de 0,10 au crédit.', $refused->getMessage());
        }

        self::assertSame(0, $this->entries()->count([]));
        self::assertSame([], $this->audit(AuditAction::EntryDrafted));
    }

    public function testValidationNumbersInDateOrderWithoutAGapPerJournalAndExercise(): void
    {
        $dossier = $this->ledgerDossier();
        $third = $this->entry($dossier, 'ACH', '2026-01-20', 'A3', self::PURCHASE);
        $first = $this->entry($dossier, 'ACH', '2026-01-05', 'A1', self::PURCHASE);
        $discarded = $this->entry($dossier, 'ACH', '2026-01-06', 'A-', self::PURCHASE);
        $second = $this->entry($dossier, 'ACH', '2026-01-10', 'A2', self::PURCHASE);
        $sale = $this->entry($dossier, 'VTE', '2026-01-06', 'V1', ['411000/C0019 D 1440.00', '701000 C 1200.00', '445710 C 240.00']);
        $ledger = self::getContainer()->get(Ledger::class);
        $ledger->discard($discarded, 'test');

        $ledger->validate([$third, $sale, $first, $second], 'test');

        self::assertSame([1, 2, 3], [$first->getNumber(), $second->getNumber(), $third->getNumber()]);
        self::assertSame(['ACH-2026-00001', 'ACH-2026-00002', 'ACH-2026-00003'], [$first->getNumberLabel(), $second->getNumberLabel(), $third->getNumberLabel()]);
        self::assertSame('VTE-2026-00001', $sale->getNumberLabel(), 'Chaque journal a sa suite.');
        self::assertSame('2026-03-15', $first->getValidatedOn()?->format('Y-m-d'), 'Validée à la date du dossier.');
        self::assertCount(4, $this->audit(AuditAction::EntryValidated));

        $fourth = $this->entry($dossier, 'ACH', '2026-01-02', 'A4', self::PURCHASE, true);
        self::assertSame(4, $fourth->getNumber(), 'La suite continue dans l’ordre des validations.');

        self::getContainer()->get(Exercises::class)->open($dossier, '2027', new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2027-12-31'));
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        self::assertSame('ACH-2027-00001', $this->entry($dossier, 'ACH', '2027-01-04', 'B1', self::PURCHASE, true)->getNumberLabel(), 'Chaque exercice repart de 1.');
    }

    public function testAValidationIsAllOrNothing(): void
    {
        $dossier = $this->ledgerDossier();
        $good = $this->entry($dossier, 'ACH', '2026-01-05', 'A1', self::PURCHASE);
        $late = $this->entry($dossier, 'ACH', '2026-02-05', 'A2', self::PURCHASE);
        $period = self::getContainer()->get(PeriodRepository::class)->findAt($dossier, new \DateTimeImmutable('2026-02-05'));
        self::assertNotNull($period);
        $period->close();
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        try {
            self::getContainer()->get(Ledger::class)->validate([$good, $late], 'test');
            self::fail('Une période close depuis le brouillard refuse la validation.');
        } catch (EntryRefused $refused) {
            self::assertStringContainsString("ACH · 05/02/2026 · A2 · 4\u{202F}158,12 : La période février 2026 est close", $refused->getMessage());
        }
        self::assertSame([EntryState::Draft, EntryState::Draft], [$good->getState(), $late->getState()]);
        self::assertSame(0, self::getContainer()->get(EntryRepository::class)->lastNumber($good->getJournal(), $good->getExercise()));
    }

    public function testTheBrouillardIsValidatedByJournalOrByPeriod(): void
    {
        $dossier = $this->ledgerDossier();
        $january = $this->entry($dossier, 'ACH', '2026-01-05', 'A1', self::PURCHASE);
        $february = $this->entry($dossier, 'ACH', '2026-02-05', 'A2', self::PURCHASE);
        $sale = $this->entry($dossier, 'VTE', '2026-01-06', 'V1', ['411000/C0019 D 1440.00', '701000 C 1440.00']);
        $ledger = self::getContainer()->get(Ledger::class);

        $period = self::getContainer()->get(PeriodRepository::class)->findAt($dossier, new \DateTimeImmutable('2026-01-15'));
        self::assertSame(1, $ledger->validateDrafts($january->getJournal(), $period, 'test'));
        self::assertSame([true, false, false], [$january->isValidated(), $february->isValidated(), $sale->isValidated()]);

        self::assertSame(1, $ledger->validateDrafts(self::getContainer()->get(JournalRepository::class)->findOneByCode($dossier, 'VTE'), null, 'test'));
        self::assertSame(1, $ledger->validateDrafts(null, null, 'test'));
        self::assertTrue($february->isValidated());
    }

    public function testADraftIsChangedOrDeletedAValidatedEntryNever(): void
    {
        $dossier = $this->ledgerDossier();
        $ledger = self::getContainer()->get(Ledger::class);
        $entry = $this->entry($dossier, 'ACH', '2026-01-08', 'FA-2601-0012', self::PURCHASE);

        $draft = EntryDraft::of($entry);
        $draft->lines = [EntryLineDraft::debit('606100', Money::cents(11_760)), EntryLineDraft::credit('401000', Money::cents(11_760), '', 'F0001')];
        $draft->document = 'FT-2601-30418';
        $ledger->update($entry, $draft, 'test');
        self::assertSame(['FT-2601-30418', 2, 11_760], [$entry->getDocument(), \count($entry->getLines()), $entry->totalCredit()->cents]);
        self::assertCount(2, self::getContainer()->get(EntityManagerInterface::class)->getRepository(EntryLine::class)->findAll(), 'Les anciennes lignes sont supprimées.');

        $ledger->validate([$entry], 'test');
        foreach ([static fn () => $ledger->update($entry, $draft, 'test'), static fn () => $ledger->discard($entry, 'test')] as $gesture) {
            try {
                $gesture();
                self::fail('Une écriture validée ne change plus.');
            } catch (EntryRefused $refused) {
                self::assertMatchesRegularExpression('/^L’écriture ACH-2026-00001 est validée : on ne peut plus la (modifier|supprimer), seulement la contrepasser\.$/', $refused->getMessage());
            }
        }
    }

    public function testTheGuardRefusesWhatGoesAroundTheLedger(): void
    {
        $dossier = $this->ledgerDossier();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entry = $this->entry($dossier, 'ACH', '2026-01-08', 'FA-2601-0012', self::PURCHASE, true);
        $line = $entry->getLines()[0];

        // The lettering letter is the one thing a validated line still takes (step 1.6).
        self::setProperty($line, 'letter', 'A');
        $entityManager->flush();
        self::assertSame('A', $line->getLetter());

        self::setProperty($line, 'debit', Money::cents(1));
        $this->assertRefusedAtFlush('/validated: it is never changed \(a line changed \(debit\)\)/');
        $entityManager->refresh($line);

        $entry->ledgerRewrite($entry->getJournal(), $entry->getPeriod(), $entry->getEntryDate(), 'AUTRE', $entry->getLabel());
        $this->assertRefusedAtFlush('/validated: it is never changed/');
        $entityManager->refresh($entry);

        $entityManager->remove($entry);
        $this->assertRefusedAtFlush('/validated: it is never deleted/');
    }

    public function testTheGuardRefusesAnUnbalancedEntryWrittenByHand(): void
    {
        $dossier = $this->ledgerDossier();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $journal = self::getContainer()->get(JournalRepository::class)->findOneByCode($dossier, 'OD');
        $period = self::getContainer()->get(PeriodRepository::class)->findAt($dossier, new \DateTimeImmutable('2026-01-08'));
        $draft = $this->entry($dossier, 'ACH', '2026-01-08', 'X', self::PURCHASE);
        self::assertNotNull($journal);
        self::assertNotNull($period);

        self::setProperty($draft->getLines()[0], 'debit', Money::cents(1));
        $this->assertRefusedAtFlush('/debit and credit differ/');
        $entityManager->refresh($draft->getLines()[0]);

        $entry = new Entry($dossier, $journal, $period, new \DateTimeImmutable('2026-01-08'), 'OD-1', 'À la main', EntryOrigin::Manual);
        $entry->ledgerReplaceLines([new EntryLine($entry, 1, $draft->getLines()[0]->getAccount(), null, 'x', Money::cents(100), Money::zero(), null)]);
        $entityManager->persist($entry);
        $this->assertRefusedAtFlush('/fewer than two lines/');
    }

    public function testAValidatedEntryIsReversedOnceByItsMirror(): void
    {
        $dossier = $this->ledgerDossier();
        $ledger = self::getContainer()->get(Ledger::class);
        $entry = $this->entry($dossier, 'ACH', '2026-01-08', 'FA-2601-0012', self::PURCHASE, true, 'Scierie, chêne');

        $reversal = $ledger->reverse($entry, new \DateTimeImmutable('2026-03-15'), 'test');

        self::assertSame(EntryOrigin::Reversal, $reversal->getOrigin());
        self::assertSame($entry, $reversal->getReversalOf());
        self::assertSame(['ACH-2026-00002', 'FA-2601-0012', '2026-03-15'], [$reversal->getNumberLabel(), $reversal->getDocument(), $reversal->getEntryDate()->format('Y-m-d')]);
        self::assertSame('Contrepassation de ACH-2026-00001 : Scierie, chêne', $reversal->getLabel());
        self::assertSame(
            [['607000', 0, 346_510], ['445660', 0, 69_302], ['401000', 415_812, 0]],
            array_map(static fn (EntryLine $l): array => [$l->getAccount()->getNumber(), $l->getDebit()->cents, $l->getCredit()->cents], $reversal->getLines()),
        );
        self::assertSame('F0001', $reversal->getLines()[2]->getThirdParty()?->getCode());
        self::assertSame($reversal, self::getContainer()->get(EntryRepository::class)->findReversalOf($entry));
        self::assertCount(1, $this->audit(AuditAction::EntryReversed));

        foreach ([
            [$entry, 'L’écriture ACH-2026-00001 est déjà contrepassée par ACH-2026-00002.'],
            [$reversal, 'L’écriture ACH-2026-00002 est une contrepassation : saisissez de nouveau l’écriture d’origine plutôt que de la contrepasser.'],
            [$this->entry($dossier, 'ACH', '2026-01-09', 'B', self::PURCHASE), 'Une écriture du brouillard se modifie ou se supprime : seule une écriture validée se contrepasse.'],
        ] as [$refused, $why]) {
            try {
                $ledger->reverse($refused, new \DateTimeImmutable('2026-03-15'), 'test');
                self::fail($why);
            } catch (EntryRefused $refusal) {
                self::assertSame($why, $refusal->getMessage());
            }
        }
    }

    public function testAReversalIsDatedInAnOpenPeriod(): void
    {
        $dossier = $this->ledgerDossier();
        $entry = $this->entry($dossier, 'ACH', '2026-01-08', 'FA-2601-0012', self::PURCHASE, true);
        $period = self::getContainer()->get(PeriodRepository::class)->findAt($dossier, new \DateTimeImmutable('2026-01-08'));
        self::assertNotNull($period);
        $period->close();
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->expectExceptionObject(new EntryRefused(['date' => 'La période janvier 2026 est close : elle ne prend plus d’écriture.']));
        self::getContainer()->get(Ledger::class)->reverse($entry, new \DateTimeImmutable('2026-01-31'), 'test');
    }

    private function assertRefusedAtFlush(string $pattern): void
    {
        try {
            self::getContainer()->get(EntityManagerInterface::class)->flush();
            self::fail('LedgerGuard refuse ce flush.');
        } catch (LedgerRefused $refused) {
            self::assertMatchesRegularExpression($pattern, $refused->getMessage());
        }
    }

    private static function setProperty(object $object, string $property, mixed $value): void
    {
        new \ReflectionProperty($object, $property)->setValue($object, $value);
    }

    private function entries(): EntryRepository
    {
        return self::getContainer()->get(EntryRepository::class);
    }

    /** @return list<AuditEvent> */
    private function audit(AuditAction $action): array
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getRepository(AuditEvent::class)->findBy(['action' => $action], ['id' => 'ASC']);
    }
}
