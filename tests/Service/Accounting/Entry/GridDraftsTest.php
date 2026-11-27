<?php

declare(strict_types=1);

namespace App\Tests\Service\Accounting\Entry;

use App\Entity\Accounting\Account;
use App\Entity\Accounting\Entry;
use App\Entity\Accounting\EntryLine;
use App\Entity\Accounting\Journal;
use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\Exercise;
use App\Entity\Dossier\Period;
use App\Entity\ThirdParty\PaymentTerms;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Accounting\AccountOrigin;
use App\Enum\Accounting\EntryOrigin;
use App\Enum\Accounting\JournalType;
use App\Enum\Dossier\DossierKind;
use App\Enum\ThirdParty\PaymentMethod;
use App\Enum\ThirdParty\PaymentTermKind;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Service\Accounting\Chart\AccountNumber;
use App\Service\Accounting\Entry\GridDrafts;
use App\Service\Accounting\Entry\GridGroup;
use App\Service\Accounting\Entry\GridLine;
use App\Service\Shared\Money;
use App\Tests\Support\DossierFixtures;
use PHPUnit\Framework\TestCase;

/**
 * From the grid to the Ledger and back, on entities held in memory. The purchase is the
 * demonstration's FA-2601-0012 of the 08/01/2026: 3 380,10 + 85,00 + 693,02 = 4 158,12 (by hand);
 * the Scierie's terms, 30 days from the invoice, put its due date on the 07/02/2026 (8 + 30 days =
 * 38 January = 7 February).
 */
final class GridDraftsTest extends TestCase
{
    /** @var array<string, Account> */
    private array $accounts = [];

    /** @var array<string, ThirdParty> */
    private array $thirdParties = [];

    private Dossier $dossier;

    protected function setUp(): void
    {
        $this->dossier = new Dossier(DossierKind::Template, DossierFixtures::company(), new \DateTimeImmutable('2026-01-31'), null, null, new \DateTimeImmutable('2026-11-27 10:00'), null);
        foreach (['401000', '411000', '419100', '455100', '512200', '601000', '624100', '445660', '627800'] as $number) {
            $this->accounts[$number] = new Account($this->dossier, $number, 'Compte '.$number, AccountNumber::isLettrableByDefault($number), AccountNumber::isCollectiveByDefault($number), AccountOrigin::Reference);
        }
        $scierie = new ThirdParty($this->dossier, 'F0001', ThirdPartyKind::Supplier, 'Scierie du Haut-Forez');
        $scierie->keepOn($this->accounts['401000'], '401SCIE');
        $scierie->settle(new PaymentTerms(PaymentTermKind::Net, 30), PaymentMethod::Transfer, null);
        $girard = new ThirdParty($this->dossier, 'C0019', ThirdPartyKind::Customer, 'Girard, Jean et Anne');
        $girard->keepOn($this->account('411000'), '411GIRA');
        $shop = new ThirdParty($this->dossier, 'F0021', ThirdPartyKind::Supplier, 'Station U');
        $this->thirdParties = ['F0001' => $scierie, '401SCIE' => $scierie, 'C0019' => $girard, '411GIRA' => $girard, 'F0021' => $shop];
    }

    public function testAnAuxiliaryCodeNamesItsThirdPartyOnItsCollectiveAndItsTermsGiveTheDueDate(): void
    {
        $made = $this->draft(new GridGroup('k1', null, '2026-01-08', 'FA-2601-0012', [
            new GridLine('601000', '', 'Scierie Haut-Forez FA 0012', 338_010),
            new GridLine('624100', '', 'Scierie Haut-Forez FA 0012', 8_500),
            new GridLine('445660', '', 'Scierie Haut-Forez FA 0012', 69_302),
            new GridLine('401scie', '', 'Scierie Haut-Forez FA 0012', 0, 415_812),
        ]), 'ACH', JournalType::Purchases);

        $draft = $made['draft'];
        self::assertSame([], $made['notes']);
        self::assertNull($made['counterpartIndex']);
        self::assertSame('Scierie Haut-Forez FA 0012', $draft->label, 'Le libellé de l’écriture est celui de sa première ligne.');
        self::assertSame(['601000', '624100', '445660', '401000'], array_map(static fn ($line): string => $line->account, $draft->lines));
        self::assertSame(['', '', '', 'F0001'], array_map(static fn ($line): string => $line->thirdParty, $draft->lines));
        self::assertSame('2026-02-07', $draft->lines[3]->dueDate?->format('Y-m-d'));
        self::assertNull($draft->lines[0]->dueDate, 'Une charge n’a pas d’échéance.');
        self::assertSame(415_812, $draft->lines[3]->credit->cents);
    }

    public function testADueDateTypedWinsAndABankJournalComputesNone(): void
    {
        $typed = $this->draft(new GridGroup('k1', null, '2026-01-08', 'FA-1', [new GridLine('601000', '', 'x', 1_000), new GridLine('401SCIE', '', 'x', 0, 1_000, '2026-03-01')]), 'ACH', JournalType::Purchases);
        self::assertSame('2026-03-01', $typed['draft']->lines[1]->dueDate?->format('Y-m-d'));

        $bank = $this->draft(new GridGroup('k2', null, '2026-01-05', 'VIR-SCIE-0431', [new GridLine('401SCIE', '', 'Virement Scierie', 787_800)]), 'BQ2', JournalType::Bank, '512200');
        self::assertNull($bank['draft']->lines[0]->dueDate, 'Un règlement n’a pas d’échéance.');
    }

    public function testABankJournalAddsItsCounterpartLineThatBalancesTheLinesTyped(): void
    {
        $made = $this->draft(new GridGroup('k1', null, '2026-01-31', 'FRAIS-BQ2-01', [
            new GridLine('627800', '', 'Frais bancaires', 3_200),
        ]), 'BQ2', JournalType::Bank, '512200');

        $draft = $made['draft'];
        self::assertSame(1, $made['counterpartIndex']);
        self::assertCount(2, $draft->lines);
        self::assertSame('512200', $draft->lines[1]->account);
        self::assertSame(3_200, $draft->lines[1]->credit->cents);
        self::assertTrue($draft->lines[1]->debit->isZero());

        $receipt = $this->draft(new GridGroup('k2', null, '2026-01-09', 'REM-0001', [new GridLine('411GIRA', '', 'Remise Girard', 0, 667_800)]), 'BQ2', JournalType::Bank, '512200');
        self::assertSame(667_800, $receipt['draft']->lines[1]->debit->cents, 'Un encaissement : la banque au débit.');
    }

    public function testACollectiveTypedByNumberTakesItsThirdPartyFromTheTiersCell(): void
    {
        $made = $this->draft(new GridGroup('k1', null, '2026-01-06', 'FV-2026-0001', [
            new GridLine('419100', '411GIRA', 'Acompte Girard', 286_200),
            new GridLine('411GIRA', '', 'Acompte Girard', 0, 286_200),
        ]), 'OD', JournalType::General);

        self::assertSame([], $made['notes']);
        self::assertSame(['419100', '411000'], array_map(static fn ($line): string => $line->account, $made['draft']->lines));
        self::assertSame(['C0019', 'C0019'], array_map(static fn ($line): string => $line->thirdParty, $made['draft']->lines));
    }

    public function testACodeThatIsNeitherAccountNorThirdPartyIsNamed(): void
    {
        $made = $this->draft(new GridGroup('k1', null, '2026-01-06', 'X', [new GridLine('401XYZ', '', 'x', 100), new GridLine('F0021', '', 'x', 0, 100), new GridLine('419100', 'ZZZ', 'x', 100)]), 'OD', JournalType::General);

        self::assertSame('Ni compte ni tiers ne répond à 401XYZ. F4 pour chercher.', $made['notes']['line.0.account']);
        self::assertSame('Le tiers F0021 n’est tenu sur aucun compte : il ne se saisit pas sur une écriture.', $made['notes']['line.1.account']);
        self::assertSame('Le tiers ZZZ n’existe pas dans le dossier.', $made['notes']['line.2.thirdParty']);
    }

    public function testBlankLinesAreLeftOutAndALabelAloneIsBlank(): void
    {
        $group = new GridGroup('k1', null, '2026-01-06', 'X', [new GridLine('601000', '', 'Achat', 100), new GridLine('', '', 'Achat'), new GridLine('401SCIE', '', 'Achat', 0, 100)]);
        self::assertCount(2, $group->typedLines());
        self::assertCount(2, $this->draft($group, 'ACH', JournalType::Purchases)['draft']->lines);
        self::assertTrue((new GridGroup('k2', null, '2026-01-06'))->isBlank());
        self::assertFalse((new GridGroup('k3', 12))->isBlank(), 'Un brouillon dont toutes les lignes sont supprimées n’est pas vide : F10 le supprime.');
    }

    public function testAnEntryIsShownWithItsAuxiliaryCodesAndWithoutItsAutomaticCounterpart(): void
    {
        $exercise = new Exercise($this->dossier, '2026', new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'));
        $january = new Period($exercise, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31'));
        $journal = new Journal($this->dossier, 'BQ2', 'Banque Populaire', JournalType::Bank, $this->account('512200'));
        $entry = new Entry($this->dossier, $journal, $january, new \DateTimeImmutable('2026-01-06'), 'VIR-1', 'Acompte', EntryOrigin::Manual);
        $entry->ledgerReplaceLines([
            new EntryLine($entry, 1, $this->account('419100'), $this->thirdParties['C0019'], 'Acompte', Money::cents(1_000), Money::zero(), null),
            new EntryLine($entry, 2, $this->account('411000'), $this->thirdParties['C0019'], 'Acompte', Money::cents(500), Money::zero(), null),
            new EntryLine($entry, 3, $this->account('512200'), null, 'Acompte', Money::zero(), Money::cents(1_500), null),
        ]);

        $lines = GridDrafts::linesOf($entry, '512200');

        self::assertCount(2, $lines);
        self::assertSame(['419100', '411GIRA'], array_map(static fn (GridLine $line): string => $line->account, $lines));
        self::assertSame(['411GIRA', ''], array_map(static fn (GridLine $line): string => $line->thirdParty, $lines));
    }

    private function account(string $number): Account
    {
        return $this->accounts[$number] ?? throw new \LogicException($number);
    }

    /** @return array{draft: \App\Service\Accounting\Ledger\EntryDraft, notes: array<string, string>, counterpartIndex: ?int} */
    private function draft(GridGroup $group, string $journal, JournalType $type, ?string $counterpart = null): array
    {
        return GridDrafts::draft($group, $journal, $type, $counterpart, fn (string $number): ?Account => $this->accounts[$number] ?? null, fn (string $code): ?ThirdParty => $this->thirdParties[$code] ?? null);
    }
}
