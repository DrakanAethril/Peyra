<?php

declare(strict_types=1);

namespace App\Tests\Service\Accounting\Ledger;

use App\Entity\Accounting\Account;
use App\Entity\Accounting\Journal;
use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\Exercise;
use App\Entity\Dossier\Period;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Accounting\AccountOrigin;
use App\Enum\Accounting\JournalType;
use App\Enum\Dossier\DossierKind;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Service\Accounting\Chart\AccountNumber;
use App\Service\Accounting\Ledger\EntryDraft;
use App\Service\Accounting\Ledger\EntryFields;
use App\Service\Accounting\Ledger\EntryLineDraft;
use App\Service\Accounting\Ledger\EntryRules;
use App\Service\Shared\Money;
use App\Tests\Support\DossierFixtures;
use PHPUnit\Framework\TestCase;

/**
 * EntryRules on entities held in memory: the one reading of an entry the Ledger applies. The
 * amounts are those of the demonstration's purchase FA-2601-0012 (3 465,10 + 693,02 = 4 158,12),
 * computed by hand.
 */
final class EntryRulesTest extends TestCase
{
    /** @var array<string, Account> */
    private array $accounts = [];

    /** @var array<string, ThirdParty> */
    private array $thirdParties = [];

    private Journal $purchases;

    private Journal $opening;

    private Period $january;

    private Period $february;

    private Period $december2025;

    protected function setUp(): void
    {
        $dossier = new Dossier(DossierKind::Template, DossierFixtures::company(), new \DateTimeImmutable('2026-01-15'), null, null, new \DateTimeImmutable('2026-11-27 10:00'), null);
        foreach (['401000', '411000', '416000', '455100', '512000', '607000', '445660', '101300'] as $number) {
            $this->accounts[$number] = new Account($dossier, $number, 'Compte '.$number, AccountNumber::isLettrableByDefault($number), AccountNumber::isCollectiveByDefault($number), AccountOrigin::Reference);
        }
        $this->thirdParties['F0001'] = $this->thirdParty($dossier, 'F0001', ThirdPartyKind::Supplier, '401000', '401SCIE');
        $this->thirdParties['C0019'] = $this->thirdParty($dossier, 'C0019', ThirdPartyKind::Customer, '411000', '411GIRA');
        $this->thirdParties['A0001'] = $this->thirdParty($dossier, 'A0001', ThirdPartyKind::Other, '455100', null);
        $this->thirdParties['F0021'] = $this->thirdParty($dossier, 'F0021', ThirdPartyKind::Supplier, null, null);
        $this->purchases = new Journal($dossier, 'ACH', 'Achats', JournalType::Purchases, null);
        $this->opening = new Journal($dossier, 'AN', 'À-nouveaux', JournalType::Opening, null);

        $exercise = new Exercise($dossier, '2026', new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'));
        $this->january = new Period($exercise, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31'));
        $this->february = new Period($exercise, new \DateTimeImmutable('2026-02-01'), new \DateTimeImmutable('2026-02-28'));
        $this->february->close();
        $past = new Exercise($dossier, '2025', new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2025-12-31'));
        $past->close();
        $this->december2025 = new Period($past, new \DateTimeImmutable('2025-12-01'), new \DateTimeImmutable('2025-12-31'));
    }

    public function testABalancedPurchaseIsAcceptedWithItsAccountsAndThirdParty(): void
    {
        $draft = $this->purchase();
        $fields = $this->check($draft);

        self::assertSame([], $draft->errors);
        self::assertNotNull($fields);
        self::assertSame($this->purchases, $fields->journal);
        self::assertSame($this->january, $fields->period);
        self::assertSame(['607000', '445660', '401000'], array_map(static fn ($line): string => $line->account->getNumber(), $fields->lines));
        self::assertSame([null, null, 'F0001'], array_map(static fn ($line): ?string => $line->thirdParty?->getCode(), $fields->lines));
        self::assertSame([346_510, 69_302, 0], array_map(static fn ($line): int => $line->debit->cents, $fields->lines));
        self::assertSame([0, 0, 415_812], array_map(static fn ($line): int => $line->credit->cents, $fields->lines));
        self::assertSame('Scierie du Haut-Forez, chêne', $fields->lines[0]->label, 'Une ligne sans libellé prend celui de l’écriture.');
        self::assertSame('TVA 20 %', $fields->lines[1]->label);
        self::assertSame('2026-02-07', $fields->lines[2]->dueDate?->format('Y-m-d'));
    }

    public function testTextsComeBackNormalizedAndBlankLinesAreSkipped(): void
    {
        $draft = $this->purchase();
        $draft->journal = ' ach ';
        $draft->document = '  FA-2601-0012  ';
        $draft->label = "Scierie   du Haut-Forez,\tchêne";
        $draft->lines[2]->thirdParty = ' f0001 ';
        $draft->lines[2]->account = '401 000';
        $draft->lines[] = new EntryLineDraft();

        self::assertNotNull($this->check($draft), implode(' ', $draft->errors));
        self::assertSame(['ACH', 'FA-2601-0012', 'Scierie du Haut-Forez, chêne', 'F0001', '401000'], self::texts($draft));
    }

    public function testAnUnbalancedEntryNamesTheSideToComplete(): void
    {
        $draft = $this->purchase();
        $draft->lines[2]->credit = Money::cents(415_811);
        self::assertNull($this->check($draft));
        self::assertSame(['lines' => 'L’écriture n’est pas équilibrée : écart de 0,01 au crédit.'], $draft->errors);

        $draft = $this->purchase();
        $draft->lines[2]->credit = Money::cents(425_812);
        self::assertNull($this->check($draft));
        self::assertSame('L’écriture n’est pas équilibrée : écart de 100,00 au débit.', $draft->errors['lines'] ?? null);
    }

    public function testAnEntryHasTwoLinesAtLeast(): void
    {
        $draft = new EntryDraft('ACH', new \DateTimeImmutable('2026-01-08'), 'X', 'Une ligne', [EntryLineDraft::debit('607000', Money::cents(100)), new EntryLineDraft()]);

        self::assertNull($this->check($draft));
        self::assertSame('Une écriture compte au moins deux lignes.', $draft->errors['lines'] ?? null);
    }

    public function testEachLineCarriesOnePositiveAmount(): void
    {
        $draft = $this->purchase();
        $draft->lines[0]->credit = Money::cents(100);
        $draft->lines[1]->debit = Money::cents(-69_302);
        $draft->lines[] = new EntryLineDraft('607000', '', 'Rien');

        self::assertNull($this->check($draft));
        self::assertSame('Une ligne porte un débit ou un crédit, pas les deux.', $draft->errors['line.0.amount'] ?? null);
        self::assertSame('Un montant est positif : portez-le dans l’autre colonne.', $draft->errors['line.1.amount'] ?? null);
        self::assertSame('Indiquez un montant au débit ou au crédit.', $draft->errors['line.3.amount'] ?? null);
    }

    public function testTheHeadOfTheEntryIsRequired(): void
    {
        $draft = $this->purchase();
        [$draft->journal, $draft->date, $draft->document, $draft->label] = ['', null, '', ''];

        self::assertNull($this->check($draft));
        self::assertSame([
            'journal' => 'Choisissez le journal.',
            'date' => 'Indiquez la date de l’écriture.',
            'document' => 'Indiquez la pièce : son numéro ou sa référence.',
            'label' => 'Indiquez le libellé de l’écriture.',
        ], array_intersect_key($draft->errors, array_flip(['journal', 'date', 'document', 'label'])));

        $draft = $this->purchase();
        [$draft->journal, $draft->document, $draft->lines[0]->account, $draft->lines[2]->thirdParty] = ['XYZ', str_repeat('A', 41), '609999', 'F9999'];
        self::assertNull($this->check($draft));
        self::assertSame('Le journal XYZ n’existe pas dans le dossier.', $draft->errors['journal'] ?? null);
        self::assertSame('Une référence de pièce tient en 40 caractères.', $draft->errors['document'] ?? null);
        self::assertSame('Le compte 609999 n’est pas dans le plan du dossier.', $draft->errors['line.0.account'] ?? null);
        self::assertSame('Le tiers F9999 n’existe pas dans le dossier.', $draft->errors['line.2.thirdParty'] ?? null);
    }

    public function testAClosedPeriodOrExerciseRefusesTheEntry(): void
    {
        $draft = $this->purchase();
        $draft->date = new \DateTimeImmutable('2026-02-10');
        self::assertNull($this->check($draft));
        self::assertSame('La période février 2026 est close : elle ne prend plus d’écriture.', $draft->errors['date'] ?? null);

        $draft = $this->purchase();
        $draft->date = new \DateTimeImmutable('2025-12-20');
        self::assertNull($this->check($draft));
        self::assertSame('L’exercice 2025 est clos : il ne prend plus d’écriture.', $draft->errors['date'] ?? null);

        $draft = $this->purchase();
        $draft->date = new \DateTimeImmutable('2027-03-01');
        self::assertNull($this->check($draft));
        self::assertSame('Le 01/03/2027 n’est dans aucun exercice du dossier.', $draft->errors['date'] ?? null);
    }

    public function testALineOnACollectiveAccountNamesAThirdPartyOfItsKind(): void
    {
        $draft = $this->purchase();
        $draft->lines[2]->thirdParty = '';
        self::assertNull($this->check($draft));
        self::assertSame('Le compte 401000 est collectif : indiquez le tiers.', $draft->errors['line.2.thirdParty'] ?? null);

        $draft = $this->purchase();
        $draft->lines[2]->thirdParty = 'C0019';
        self::assertNull($this->check($draft));
        self::assertSame('Le tiers C0019 ne se saisit pas sur le compte 401000. Le compte d’un client est un compte de clients (411000, 416000…).', $draft->errors['line.2.thirdParty'] ?? null);

        $draft = $this->purchase();
        $draft->lines[2]->thirdParty = 'A0001';
        self::assertNull($this->check($draft));
        self::assertSame('Le tiers A0001 n’a pas de code auxiliaire : saisissez-le sur son compte 455100.', $draft->errors['line.2.thirdParty'] ?? null);

        $draft = $this->purchase();
        $draft->lines[2]->thirdParty = 'F0021';
        self::assertNull($this->check($draft));
        self::assertSame('Le tiers F0021 n’est tenu sur aucun compte : il ne se saisit pas sur une écriture.', $draft->errors['line.2.thirdParty'] ?? null);
    }

    public function testACustomerGoesOnAnotherCollectiveOfItsNature(): void
    {
        // A doubtful debt moved to 416000: 6 840,00 from the customer's 411000 to its 416000.
        $draft = new EntryDraft('ACH', new \DateTimeImmutable('2026-01-20'), 'OD-1', 'Client douteux', [
            EntryLineDraft::debit('416000', Money::cents(684_000), '', 'C0019'),
            EntryLineDraft::credit('411000', Money::cents(684_000), '', 'C0019'),
        ]);

        self::assertNotNull($this->check($draft), implode(' ', $draft->errors));
    }

    public function testAGeneralAccountTakesOnlyTheThirdPartyKeptOnIt(): void
    {
        $line = static fn (string $account, string $thirdParty): EntryDraft => new EntryDraft('ACH', new \DateTimeImmutable('2026-01-20'), 'CC-1', 'Apport en compte courant', [
            EntryLineDraft::debit('512000', Money::cents(150_000)),
            EntryLineDraft::credit($account, Money::cents(150_000), '', $thirdParty),
        ]);

        self::assertNotNull($this->check($line('455100', 'A0001')), 'L’associé sur son compte 455100.');
        self::assertNotNull($this->check($line('455100', '')), 'Le tiers est facultatif sur un compte général.');

        $draft = $line('101300', 'A0001');
        self::assertNull($this->check($draft));
        self::assertSame('Le tiers A0001 est tenu sur le compte 455100, pas sur le 101300.', $draft->errors['line.1.thirdParty'] ?? null);

        $draft = $line('101300', 'F0021');
        self::assertNull($this->check($draft));
        self::assertSame('Le tiers F0021 n’est tenu sur aucun compte : il ne se saisit pas sur une écriture.', $draft->errors['line.1.thirdParty'] ?? null);
    }

    public function testADueDateGoesOnALettrableAccountOnly(): void
    {
        $draft = $this->purchase();
        $draft->lines[0]->dueDate = new \DateTimeImmutable('2026-02-07');

        self::assertNull($this->check($draft));
        self::assertSame('Le compte 607000 n’est pas lettrable : une échéance se porte sur un compte de tiers.', $draft->errors['line.0.dueDate'] ?? null);
    }

    /** @return list<string> the head's texts and the third line's account and third party, as the rules left them */
    private static function texts(EntryDraft $draft): array
    {
        return [$draft->journal, $draft->document, $draft->label, $draft->lines[2]->thirdParty, $draft->lines[2]->account];
    }

    public function testAnOpeningEntryIsDatedTheFirstDayOfItsExercise(): void
    {
        $lines = [EntryLineDraft::debit('512000', Money::cents(1_000_000)), EntryLineDraft::credit('101300', Money::cents(1_000_000))];

        $first = new EntryDraft('AN', new \DateTimeImmutable('2026-01-01'), 'AN-2026-001', 'À-nouveaux', $lines);
        self::assertNotNull($this->check($first));
        self::assertSame([], $first->errors);

        $second = new EntryDraft('AN', new \DateTimeImmutable('2026-01-02'), 'AN-2026-002', 'À-nouveaux', $lines);
        self::assertNull($this->check($second));
        self::assertSame(['date' => 'Les à-nouveaux se datent du premier jour de l’exercice : le 01/01/2026.'], $second->errors);

        // A closed period says so first.
        $closed = new EntryDraft('AN', new \DateTimeImmutable('2026-02-01'), 'AN-2026-003', 'À-nouveaux', $lines);
        self::assertNull($this->check($closed));
        self::assertSame(['date' => 'La période février 2026 est close : elle ne prend plus d’écriture.'], $closed->errors);
    }

    private function purchase(): EntryDraft
    {
        return new EntryDraft('ACH', new \DateTimeImmutable('2026-01-08'), 'FA-2601-0012', 'Scierie du Haut-Forez, chêne', [
            EntryLineDraft::debit('607000', Money::cents(346_510)),
            EntryLineDraft::debit('445660', Money::cents(69_302), 'TVA 20 %'),
            EntryLineDraft::credit('401000', Money::cents(415_812), '', 'F0001', new \DateTimeImmutable('2026-02-07')),
        ]);
    }

    private function check(EntryDraft $draft): ?EntryFields
    {
        return EntryRules::check(
            $draft,
            fn (string $code): ?Journal => ['ACH' => $this->purchases, 'AN' => $this->opening][$code] ?? null,
            function (\DateTimeImmutable $day): ?Period {
                foreach ([$this->january, $this->february, $this->december2025] as $period) {
                    if ($day >= $period->getStartsOn() && $day <= $period->getEndsOn()) {
                        return $period;
                    }
                }

                return null;
            },
            fn (string $number): ?Account => $this->accounts[$number] ?? null,
            fn (string $code): ?ThirdParty => $this->thirdParties[$code] ?? null,
        );
    }

    private function thirdParty(Dossier $dossier, string $code, ThirdPartyKind $kind, ?string $account, ?string $auxiliary): ThirdParty
    {
        $thirdParty = new ThirdParty($dossier, $code, $kind, 'Tiers '.$code);
        $thirdParty->keepOn(null === $account ? null : $this->accounts[$account], $auxiliary);

        return $thirdParty;
    }
}
