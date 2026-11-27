<?php

declare(strict_types=1);

namespace App\Tests\Service\Accounting\Consultation;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Accounting\EntryState;
use App\Enum\Identity\AccountKind;
use App\Enum\Identity\EntryBasis;
use App\Repository\Accounting\EntryRepository;
use App\Repository\Accounting\JournalRepository;
use App\Repository\Dossier\PeriodRepository;
use App\Service\Accounting\Consultation\AccountStatements;
use App\Service\Accounting\Consultation\Brouillard;
use App\Service\Accounting\Consultation\BrouillardRow;
use App\Service\Accounting\Consultation\ConsultationWindow;
use App\Service\Accounting\Consultation\EntrySearch;
use App\Service\Accounting\Consultation\EntrySearchCriteria;
use App\Service\Accounting\Entry\GridGroup;
use App\Service\Accounting\Entry\GridLine;
use App\Service\Accounting\Entry\PendingEntries;
use App\Service\Accounting\Ledger\EntryScope;
use App\Service\Accounting\Ledger\LedgerMovement;
use App\Service\Shared\ListPage;
use App\Tests\Support\BuildsLedger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Consultation, search and brouillard (step 1.5a) over five entries, every figure worked out by hand:
 *
 * - AN 01/01, validated: 512000 D 10 000,00 · 411000 C0019 D 1 200,00 · 401000 F0001 C 2 400,00 ·
 *   101300 C 8 800,00;
 * - ACH 08/01, validated, « Facture Scierie »: 607000 D 3 465,10 · 445660 D 693,02 · 401000 F0001
 *   C 4 158,12;
 * - BQ 20/01, brouillard: 401000 F0001 D 2 400,00 · 512000 C 2 400,00;
 * - VTE 05/02, validated: 411000 C0019 D 1 440,00 · 701000 C 1 200,00 · 445710 C 240,00;
 * - OD 10/02, validated, an advance from Girard: 512000 D 300,00 · 419100 C0019 C 300,00.
 *
 * The dossier's date is the 15/03/2026.
 */
final class ConsultationTest extends KernelTestCase
{
    use BuildsLedger;

    public function testAnAccountsMovementsCarryTheirRunningBalance(): void
    {
        $dossier = $this->books();
        $statements = self::getContainer()->get(AccountStatements::class);
        $bank = $statements->subject($dossier, '512000') ?? throw new \LogicException();

        $statement = $statements->statement($dossier, $bank, self::window('2026-01-01', '2026-03-15'), EntryScope::WithDrafts);
        self::assertFalse($statement->hasOpening, 'Le 1er janvier ouvre l’exercice : ses à-nouveaux sont un mouvement.');
        // 10 000,00 D, then 10 000,00 − 2 400,00 = 7 600,00 D, then + 300,00 = 7 900,00 D.
        self::assertSame([['AN', 1_000_000], ['BQ', 760_000], ['OD', 790_000]], self::balances($statement->movements));
        self::assertSame([1_030_000, 240_000], [$statement->totals()->debit->cents, $statement->totals()->credit->cents]);
        self::assertSame(790_000, $statement->closing()->cents);

        // Validated only, from February: what January left is the opening, read on the 31st.
        $february = $statements->statement($dossier, $bank, self::window('2026-02-01', '2026-03-15'), EntryScope::Validated);
        self::assertTrue($february->hasOpening);
        self::assertSame('2026-01-31', $february->openingDay()->format('Y-m-d'));
        self::assertSame(1_000_000, $february->opening->balance()->cents);
        self::assertSame([['OD', 1_030_000]], self::balances($february->movements));
        self::assertSame(1_030_000, $february->closing()->cents);
    }

    public function testAThirdPartyIsReadOnEveryAccountThatNamesIt(): void
    {
        $dossier = $this->books();
        $statements = self::getContainer()->get(AccountStatements::class);
        $girard = $statements->subject($dossier, '411gira') ?? throw new \LogicException();

        self::assertTrue($girard->isThirdParty());
        self::assertSame('411GIRA', $girard->code());
        $statement = $statements->statement($dossier, $girard, self::window('2026-01-01', '2026-03-15'), EntryScope::WithDrafts);
        // 1 200,00 D, + 1 440,00 = 2 640,00 D, − 300,00 on 419100 = 2 340,00 D.
        self::assertSame([['AN', 120_000], ['VTE', 264_000], ['OD', 234_000]], self::balances($statement->movements));
        self::assertSame(['411000', '411000', '419100'], array_map(static fn (LedgerMovement $m): string => $m->line->getAccount()->getNumber(), $statement->movements));
    }

    public function testWhatIsTypedFindsAnAccountOrAThirdParty(): void
    {
        $dossier = $this->books();
        $statements = self::getContainer()->get(AccountStatements::class);

        self::assertSame('512000', $statements->subject($dossier, ' 512000 ')?->account?->getNumber());
        self::assertSame('F0001', $statements->subject($dossier, '401SCIE')?->thirdParty?->getCode());
        // A third party without an auxiliary code is typed by its code.
        self::assertSame('A0001', $statements->subject($dossier, 'a0001')?->code());
        self::assertNull($statements->subject($dossier, '999999'));
        self::assertNull($statements->subject($dossier, 'XYZ'));
        self::assertNull($statements->subject($dossier, ''));
        self::assertSame('2026-01-01', $statements->defaultFrom($dossier)->format('Y-m-d'));
    }

    public function testEveryPageOpensOnTheBalanceCarriedFromThePreviousOne(): void
    {
        $dossier = $this->books();
        // 26 more payments of 10,00 from the bank: the 27th line of 512000 sits on page 2 (25 a page).
        for ($day = 1; $day <= 26; ++$day) {
            $this->entry($dossier, 'BQ', \sprintf('2026-03-%02d', min($day, 15)), 'CHQ-'.$day, ['626000 D 10.00', '512000 C 10.00'], true);
        }
        $statements = self::getContainer()->get(AccountStatements::class);
        $statement = $statements->statement($dossier, $statements->subject($dossier, '512000') ?? throw new \LogicException(), self::window('2026-01-01', '2026-03-15'), EntryScope::WithDrafts);

        self::assertCount(29, $statement->movements);
        $second = $statement->page(ListPage::of(2, 25));
        self::assertCount(4, $second['rows']);
        // 7 900,00 after OD, then 22 payments of 10,00 on page 1: 7 680,00 carried.
        self::assertSame(768_000, $second['carried']->cents);
        self::assertSame(764_000, $second['rows'][3]->balanceAfter->cents);
        self::assertSame(764_000, $statement->closing()->cents);
        self::assertSame(0, $statement->page(ListPage::of(1, 25))['carried']->cents, 'Sans solde d’ouverture, la première page part de zéro.');
    }

    public function testTheSearchNarrowsByEveryFilterAndAddsTheWholeFilter(): void
    {
        $dossier = $this->books();
        $search = self::getContainer()->get(EntrySearch::class);

        $all = $search->page($this->criteria($dossier, []), ListPage::of(1));
        self::assertSame(5, $all->total);
        // 11 200,00 + 4 158,12 + 2 400,00 + 1 440,00 + 300,00.
        self::assertSame(1_949_812, $all->amount->cents);
        self::assertSame(['OD', 'VTE', 'BQ', 'ACH', 'AN'], self::journals($all->entries), 'La plus récente d’abord.');
        self::assertSame(['AN', 'ACH', 'BQ', 'VTE', 'OD'], self::journals($search->page($this->criteria($dossier, ['dir' => 'asc']), ListPage::of(1))->entries));

        self::assertSame(['OD', 'BQ', 'AN'], self::journals($search->page($this->criteria($dossier, ['account' => '512000']), ListPage::of(1))->entries));
        self::assertSame(['BQ', 'ACH', 'AN'], self::journals($search->page($this->criteria($dossier, ['account' => '401SCIE']), ListPage::of(1))->entries));
        self::assertSame(['ACH'], self::journals($search->page($this->criteria($dossier, ['amount' => '4158,12']), ListPage::of(1))->entries));
        self::assertSame(['BQ', 'AN'], self::journals($search->page($this->criteria($dossier, ['amount' => '2 400']), ListPage::of(1))->entries), 'Une ligne de ce montant, au débit ou au crédit.');
        self::assertSame(['BQ'], self::journals($search->page($this->criteria($dossier, ['state' => 'draft']), ListPage::of(1))->entries));
        self::assertSame(['ACH'], self::journals($search->page($this->criteria($dossier, ['q' => 'SCIERIE']), ListPage::of(1))->entries), 'Le libellé, sans casse.');
        self::assertSame(['BQ', 'ACH'], self::journals($search->page($this->criteria($dossier, ['q' => 'scie']), ListPage::of(1))->entries), 'La pièce VIR-SCIE, et le libellé « Facture Scierie ».');
        self::assertSame(['ACH'], self::journals($search->page($this->criteria($dossier, ['q' => 'fa-2601']), ListPage::of(1))->entries), 'La pièce.');
        self::assertSame(['BQ'], self::journals($search->page($this->criteria($dossier, ['journal' => 'bq']), ListPage::of(1))->entries));

        $february = $this->criteria($dossier, [], self::window('2026-02-01', '2026-02-28'));
        $result = $search->page($february, ListPage::of(1));
        self::assertSame(['OD', 'VTE'], self::journals($result->entries));
        self::assertSame(174_000, $result->amount->cents);
        self::assertSame(['all' => 5, 'draft' => 1, 'validated' => 4], $search->countByState($this->criteria($dossier, ['state' => 'draft'])));
        self::assertCount(5, $search->all($this->criteria($dossier, [])));
    }

    public function testTheBrouillardValidatesTheSelectionButWhatIsBeingChanged(): void
    {
        $dossier = $this->books();
        $second = $this->entry($dossier, 'ACH', '2026-02-12', 'FA-2602-0031', ['607000 D 100.00', '401000/F0001 C 100.00']);
        $brouillard = self::getContainer()->get(Brouillard::class);
        $me = $this->person();

        $rows = $brouillard->rows($dossier, null, null, $me);
        self::assertSame(['BQ', 'ACH'], array_map(static fn (BrouillardRow $row): string => $row->entry->getJournal()->getCode(), $rows));
        self::assertTrue($rows[0]->isSelectable());
        self::assertNull($rows[0]->reason());

        // The person is changing the ACH draft in the grid: it is held back, as Ctrl Entrée holds it.
        $journal = self::getContainer()->get(JournalRepository::class)->findOneByCode($dossier, 'ACH') ?? throw new \LogicException();
        $period = self::getContainer()->get(PeriodRepository::class)->findAt($dossier, new \DateTimeImmutable('2026-02-12')) ?? throw new \LogicException();
        self::getContainer()->get(PendingEntries::class)->keep($journal, $period, $me, [new GridGroup('e'.$second->getId(), $second->getId(), '2026-02-12', 'FA-2602-0031', [new GridLine('607000', '', 'Corrigée', 12_000)])], 0, new \DateTimeImmutable('2026-11-28 10:00'));
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $held = $brouillard->rows($dossier, $journal, $period, $me);
        self::assertCount(1, $held);
        self::assertTrue($held[0]->changing);
        self::assertFalse($held[0]->isSelectable());
        self::assertSame('Vous modifiez cette écriture dans la grille : enregistrez-la (F10) avant de la valider.', $held[0]->reason());

        $outcome = $brouillard->validate($dossier, [(int) $rows[0]->entry->getId(), (int) $second->getId(), 999_999], $me);
        self::assertNull($outcome->fault);
        self::assertSame(1, $outcome->held);
        self::assertSame('Écriture BQ-2026-00001 validée. Une écriture retenue : un contrôle l’arrête ou elle est en cours de modification.', $outcome->message());
        $entries = self::getContainer()->get(EntryRepository::class);
        self::assertSame(EntryState::Validated, $entries->find($rows[0]->entry->getId())?->getState());
        self::assertSame(EntryState::Draft, $entries->find($second->getId())?->getState());
    }

    public function testADraftWhoseMonthClosedSinceIsRefusedAndCannotBeTicked(): void
    {
        $dossier = $this->books();
        $period = self::getContainer()->get(PeriodRepository::class)->findAt($dossier, new \DateTimeImmutable('2026-01-20')) ?? throw new \LogicException();
        $period->close();
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $rows = self::getContainer()->get(Brouillard::class)->rows($dossier, null, null, $this->person());
        self::assertCount(1, $rows);
        self::assertTrue($rows[0]->blocked);
        self::assertFalse($rows[0]->isSelectable());
        self::assertNotNull($rows[0]->reason());
        $outcome = self::getContainer()->get(Brouillard::class)->validate($dossier, [(int) $rows[0]->entry->getId()], $this->person('uidNumber:9002', 'manon.chevalier'));
        self::assertSame([], $outcome->validated);
        self::assertSame('Aucune écriture validée. Une écriture retenue : un contrôle l’arrête ou elle est en cours de modification.', $outcome->message());
    }

    private function books(): Dossier
    {
        $dossier = $this->ledgerDossier();
        $this->entry($dossier, 'AN', '2026-01-01', 'AN-2026', ['512000 D 10000.00', '411000/C0019 D 1200.00', '401000/F0001 C 2400.00', '101300 C 8800.00'], true);
        $this->entry($dossier, 'ACH', '2026-01-08', 'FA-2601-0012', ['607000 D 3465.10', '445660 D 693.02', '401000/F0001 C 4158.12'], true, 'Facture Scierie');
        $this->entry($dossier, 'BQ', '2026-01-20', 'VIR-SCIE', ['401000/F0001 D 2400.00', '512000 C 2400.00']);
        $this->entry($dossier, 'VTE', '2026-02-05', 'FV-2026-001', ['411000/C0019 D 1440.00', '701000 C 1200.00', '445710 C 240.00'], true);
        $this->entry($dossier, 'OD', '2026-02-10', 'AV-GIRA', ['512000 D 300.00', '419100/C0019 C 300.00'], true);

        return $dossier;
    }

    /** @param array<string, string> $query */
    private function criteria(Dossier $dossier, array $query, ?ConsultationWindow $window = null): EntrySearchCriteria
    {
        $journals = self::getContainer()->get(JournalRepository::class);
        $statements = self::getContainer()->get(AccountStatements::class);

        return EntrySearchCriteria::read(
            $query,
            $window ?? self::window('2026-01-01', '2026-03-15'),
            static fn (string $code) => $journals->findOneByCode($dossier, $code),
            static fn (string $typed) => $statements->subject($dossier, $typed),
        );
    }

    private function person(string $id = 'uidNumber:9001', string $login = 'lucas.bernard'): User
    {
        $person = new User($id, $login, 'Lucas Bernard', AccountKind::Student, EntryBasis::StudentGroup, ['student', 'cg-1'], new \DateTimeImmutable('2026-01-01'));
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($person);
        $entityManager->flush();

        return $person;
    }

    /**
     * @param list<LedgerMovement> $movements
     *
     * @return list<array{string, int}>
     */
    private static function balances(array $movements): array
    {
        return array_map(static fn (LedgerMovement $m): array => [$m->line->getEntry()->getJournal()->getCode(), $m->balanceAfter->cents], $movements);
    }

    /**
     * @param list<\App\Entity\Accounting\Entry> $entries
     *
     * @return list<string>
     */
    private static function journals(array $entries): array
    {
        return array_map(static fn ($entry): string => $entry->getJournal()->getCode(), $entries);
    }

    private static function window(string $from, string $to): ConsultationWindow
    {
        return ConsultationWindow::of(new \DateTimeImmutable($from), new \DateTimeImmutable($to));
    }
}
