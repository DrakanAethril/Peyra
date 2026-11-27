<?php

declare(strict_types=1);

namespace App\Tests\Service\Accounting\Entry;

use App\Entity\Accounting\Account;
use App\Entity\Dossier\Dossier;
use App\Enum\Accounting\AccountOrigin;
use App\Enum\Accounting\AssistanceLevel;
use App\Enum\Accounting\JournalType;
use App\Enum\Dossier\DossierKind;
use App\Service\Accounting\Entry\EntryControl;
use App\Service\Accounting\Entry\EntryControls;
use App\Service\Accounting\Ledger\EntryDraft;
use App\Service\Accounting\Ledger\EntryLineDraft;
use App\Service\Shared\Money;
use App\Tests\Support\DossierFixtures;
use PHPUnit\Framework\TestCase;

/**
 * The controls of the grid at each assistance level: an account unusual for its journal, a date
 * outside the month entered or after the dossier's date, a document already in the journal.
 */
final class EntryControlsTest extends TestCase
{
    /** @var array<string, Account> */
    private array $accounts = [];

    protected function setUp(): void
    {
        $dossier = new Dossier(DossierKind::Template, DossierFixtures::company(), new \DateTimeImmutable('2026-01-31'), null, null, new \DateTimeImmutable('2026-11-27 10:00'), null);
        foreach (['401000' => 'Fournisseurs', '411000' => 'Clients', '512100' => 'Crédit Coopératif', '512200' => 'Banque Populaire', '530000' => 'Caisse', '580000' => 'Virements internes', '601000' => 'Achats de matières premières', '627800' => 'Frais bancaires', '701000' => 'Ventes de produits finis', '445660' => 'TVA déductible', '218200' => 'Matériel de transport'] as $number => $label) {
            $this->accounts[(string) $number] = new Account($dossier, (string) $number, $label, false, false, AccountOrigin::Reference);
        }
    }

    public function testAPurchaseOnARevenueOrACustomerOrABankIsUnusual(): void
    {
        $controls = $this->controls(['601000 D', '701000 C', '411000 C', '512200 C', '445660 D', '218200 D'], JournalType::Purchases);

        self::assertSame(['line.1.account', 'line.2.account', 'line.3.account'], array_map(static fn (EntryControl $c): string => $c->key, $controls));
        self::assertSame('Le compte 701000 (produit) est inhabituel dans un journal d’achats.', $controls[0]->message);
        self::assertSame('Le compte 512200 (banque) est inhabituel dans un journal d’achats.', $controls[2]->message);
        self::assertFalse($controls[0]->blocking, 'Au niveau « avertissements », un contrôle n’arrête rien.');
    }

    public function testASaleOnAnExpenseOrASupplierIsUnusual(): void
    {
        $controls = $this->controls(['411000 D', '601000 C', '401000 C', '701000 C'], JournalType::Sales);

        self::assertSame(['line.1.account', 'line.2.account'], array_map(static fn (EntryControl $c): string => $c->key, $controls));
        self::assertSame('Le compte 601000 (charge) est inhabituel dans un journal de ventes.', $controls[0]->message);
    }

    public function testABankJournalTakesItsOwnAccountButNotAnotherBank(): void
    {
        self::assertSame([], $this->controls(['627800 D', '512200 C', '580000 D'], JournalType::Bank, '512200'));
        $controls = $this->controls(['512100 D', '512200 C'], JournalType::Bank, '512200');
        self::assertSame(['line.0.account'], array_map(static fn (EntryControl $c): string => $c->key, $controls));
        self::assertSame('Le compte 512100 (banque) est inhabituel dans un journal de banque.', $controls[0]->message);
        self::assertSame([], $this->controls(['601000 D', '701000 C'], JournalType::General), 'Les opérations diverses prennent tout compte.');
        self::assertCount(2, $this->controls(['601000 D', '701000 C'], JournalType::Opening), 'Les à-nouveaux ne portent que le bilan.');
    }

    public function testADateOutsideTheMonthOrAfterTheDossiersDateIsSaid(): void
    {
        $february = $this->controls(['601000 D', '401000 C'], JournalType::Purchases, null, '2026-02-03');
        self::assertSame('Le 03/02/2026 n’est pas en janvier 2026 : l’écriture ira en février 2026.', $february[0]->message);
        self::assertSame('date', $february[0]->key);

        $late = $this->controls(['601000 D', '401000 C'], JournalType::Purchases, null, '2026-01-20', AssistanceLevel::Warnings, null, new \DateTimeImmutable('2026-01-15'));
        self::assertSame('Cette date est postérieure à la date du dossier (15/01/2026).', $late[0]->message);
    }

    public function testADocumentAlreadyInTheJournalIsSaid(): void
    {
        $controls = $this->controls(['601000 D', '401000 C'], JournalType::Purchases, null, '2026-01-08', AssistanceLevel::Warnings, 'ACH-2026-00007');

        self::assertSame('document', $controls[0]->key);
        self::assertSame('La pièce FA-2601-0012 est déjà saisie dans ce journal (ACH-2026-00007). Vérifiez qu’il ne s’agit pas d’un doublon.', $controls[0]->message);
    }

    public function testTheLevelDecidesWhetherAControlStopsOrWarns(): void
    {
        $lines = ['701000 D', '401000 C'];
        self::assertFalse($this->controls($lines, JournalType::Purchases, null, '2026-01-08', AssistanceLevel::Warnings)[0]->blocking);
        self::assertTrue($this->controls($lines, JournalType::Purchases, null, '2026-01-08', AssistanceLevel::Blocking)[0]->blocking);
        self::assertTrue($this->controls($lines, JournalType::Purchases, null, '2026-01-08', AssistanceLevel::Guided)[0]->blocking);
        self::assertSame([], $this->controls($lines, JournalType::Purchases, null, '2026-02-08', AssistanceLevel::None, 'ACH-2026-00001'), 'Sans filet : aucun contrôle.');
    }

    /**
     * @param list<string> $lines « number D|C »
     *
     * @return list<EntryControl>
     */
    private function controls(array $lines, JournalType $type, ?string $counterpart = null, string $date = '2026-01-08', AssistanceLevel $level = AssistanceLevel::Warnings, ?string $documentUse = null, ?\DateTimeImmutable $dossierDate = null): array
    {
        $drafts = [];
        foreach ($lines as $line) {
            [$number, $side] = explode(' ', $line);
            $drafts[] = 'D' === $side ? EntryLineDraft::debit($number, Money::cents(100)) : EntryLineDraft::credit($number, Money::cents(100));
        }

        return EntryControls::check(
            new EntryDraft('X', new \DateTimeImmutable($date), 'FA-2601-0012', 'Achat', $drafts),
            $type,
            $counterpart,
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
            $dossierDate ?? new \DateTimeImmutable('2026-01-31'),
            $level,
            fn (string $number): ?Account => $this->accounts[$number] ?? null,
            static fn (string $document): ?string => $documentUse,
        );
    }
}
