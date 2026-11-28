<?php

declare(strict_types=1);

namespace App\Tests\Service\Accounting\Consultation;

use App\Entity\Accounting\Journal;
use App\Enum\Accounting\EntryState;
use App\Service\Accounting\Consultation\ConsultationWindow;
use App\Service\Accounting\Consultation\ConsultedSubject;
use App\Service\Accounting\Consultation\EntrySearchCriteria;
use PHPUnit\Framework\TestCase;

/** « Recherche d'écritures » reads its filters: each optional, the unreadable ones said and left out. */
final class EntrySearchCriteriaTest extends TestCase
{
    public function testNoFilterIsTheWholeWindowNewestFirst(): void
    {
        $criteria = $this->read([]);

        self::assertNull($criteria->journal);
        self::assertNull($criteria->subject);
        self::assertNull($criteria->amount);
        self::assertNull($criteria->state);
        self::assertSame('', $criteria->text);
        self::assertTrue($criteria->newestFirst);
        self::assertSame([], $criteria->errors);
    }

    public function testTheAmountIsReadAsAPersonTypesIt(): void
    {
        self::assertSame(415_812, $this->read(['amount' => '4 158,12'])->amount?->cents);
        self::assertSame(415_812, $this->read(['amount' => '4158.12'])->amount?->cents);
        // A sign means nothing to « a line of this amount »: debit and credit are never negative.
        self::assertSame(13_800, $this->read(['amount' => '-138'])->amount?->cents);
    }

    public function testFiltersThatCannotBeReadAreSaidByField(): void
    {
        $criteria = $this->read(['journal' => 'xyz', 'account' => '999999', 'amount' => 'douze']);

        self::assertNull($criteria->journal);
        self::assertNull($criteria->subject);
        self::assertNull($criteria->amount);
        self::assertSame(['journal', 'account', 'amount'], array_keys($criteria->errors));
        self::assertSame('Le journal XYZ n’existe pas dans le dossier.', $criteria->errors['journal']);
        self::assertSame('Ni compte ni tiers ne répond à 999999.', $criteria->errors['account']);
    }

    public function testTheStateTheTextAndTheOrder(): void
    {
        $criteria = $this->read(['state' => 'draft', 'q' => 'Scierie', 'dir' => 'asc']);

        self::assertSame(EntryState::Draft, $criteria->state);
        self::assertSame('Scierie', $criteria->text);
        self::assertFalse($criteria->newestFirst);
        self::assertNull($this->read(['state' => 'archived'])->state);
    }

    public function testAWindowFaultIsAFilterFault(): void
    {
        $window = ConsultationWindow::read('2026-03-01', '2026-02-01', new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-03-15'));
        $criteria = EntrySearchCriteria::read([], $window, static fn (string $code): ?Journal => null, static fn (string $typed): ?ConsultedSubject => null);

        self::assertArrayHasKey('window', $criteria->errors);
    }

    /** @param array<string, string> $query */
    private function read(array $query): EntrySearchCriteria
    {
        $window = ConsultationWindow::of(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-03-15'));

        return EntrySearchCriteria::read($query, $window, static fn (string $code): ?Journal => null, static fn (string $typed): ?ConsultedSubject => null);
    }
}
