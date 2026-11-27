<?php

declare(strict_types=1);

namespace App\Tests\Service\Accounting\Subscription;

use App\Entity\Accounting\EntryTemplate;
use App\Entity\Accounting\Journal;
use App\Entity\Dossier\Dossier;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Accounting\EntrySide;
use App\Enum\Accounting\JournalType;
use App\Enum\Accounting\SubscriptionTarget;
use App\Enum\Accounting\TemplateAmount;
use App\Enum\Accounting\TemplateBase;
use App\Enum\Dossier\DossierKind;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Service\Accounting\Subscription\SubscriptionDraft;
use App\Service\Accounting\Subscription\SubscriptionFields;
use App\Service\Accounting\Subscription\SubscriptionRules;
use App\Service\Shared\Money;
use App\Tests\Support\DossierFixtures;
use PHPUnit\Framework\TestCase;

/**
 * The reading of a subscription, on templates held in memory: the demonstration's rent (LOYER,
 * 3 200,00 HT at 20 %, its supplier named on its lines), a sale at 20 % whose customer the call
 * asks (VENTE20), a supplier's payment with an amount to type (REGLFOUR). The dossier's exercise is
 * 2026, so its months are January to December 2026.
 */
final class SubscriptionRulesTest extends TestCase
{
    /** @var array<string, EntryTemplate> */
    private array $templates = [];

    protected function setUp(): void
    {
        $dossier = new Dossier(DossierKind::Template, DossierFixtures::company(), new \DateTimeImmutable('2026-01-31'), null, null, new \DateTimeImmutable('2026-11-27 10:00'), null);
        $purchases = new Journal($dossier, 'ACH', 'Achats', JournalType::Purchases, null);
        $sales = new Journal($dossier, 'VTE', 'Ventes', JournalType::Sales, null);
        $bank = new Journal($dossier, 'BQ2', 'Banque Populaire', JournalType::Bank, null);

        $rent = new EntryTemplate($dossier, 'LOYER', 'Loyer de l’atelier', $purchases);
        $rent->describe('LOYER', 'Loyer de l’atelier', $purchases, TemplateBase::Net, '20', [
            self::line('613200', EntrySide::Debit, TemplateAmount::Net),
            self::line('445660', EntrySide::Debit, TemplateAmount::Vat),
            self::line('401SCIV', EntrySide::Credit, TemplateAmount::Gross),
        ]);
        $sale = new EntryTemplate($dossier, 'VENTE20', 'Facture de vente à 20 %', $sales);
        $sale->describe('VENTE20', 'Facture de vente à 20 %', $sales, TemplateBase::Net, '20', [
            self::line('411000', EntrySide::Debit, TemplateAmount::Gross),
            self::line('701000', EntrySide::Credit, TemplateAmount::Net),
            self::line('445710', EntrySide::Credit, TemplateAmount::Vat),
        ]);
        $payment = new EntryTemplate($dossier, 'REGLFOUR', 'Règlement d’un fournisseur', $bank);
        $payment->describe('REGLFOUR', 'Règlement d’un fournisseur', $bank, null, null, [self::line('401000', EntrySide::Debit, TemplateAmount::Typed)]);
        $this->templates = ['LOYER' => $rent, 'VENTE20' => $sale, 'REGLFOUR' => $payment];
    }

    public function testTheRentIsASubscription(): void
    {
        $fields = $this->check($this->rent());

        self::assertNotNull($fields);
        self::assertSame('Loyer de l’atelier', $fields->label);
        self::assertSame('LOYER', $fields->template->getCode());
        self::assertSame(320_000, $fields->amount?->cents);
        self::assertSame('20', $fields->vatRate);
        self::assertSame('', $fields->thirdParty);
        self::assertSame(1, $fields->day);
        self::assertSame('2026-02-01', $fields->firstMonth->format('Y-m-d'));
        self::assertSame(11, $fields->occurrences);
        self::assertSame('LOY-{AAAA}-{MM}', $fields->pattern);
        self::assertSame(SubscriptionTarget::Draft, $fields->target);
    }

    public function testTheRateLeftEmptyIsTheTemplatesAndTextsAreNormalised(): void
    {
        $draft = $this->rent();
        $draft->label = '  Loyer   de l’atelier ';
        $draft->template = ' loyer';
        $draft->rate = '';

        $fields = $this->check($draft);

        self::assertNotNull($fields);
        self::assertSame('Loyer de l’atelier', $fields->label);
        self::assertSame('20', $fields->vatRate);
        self::assertSame('20', $draft->rate);
    }

    public function testEveryFaultIsNamedUnderItsField(): void
    {
        $draft = new SubscriptionDraft();
        $draft->template = 'LOYER';
        $draft->rate = '120';
        $draft->day = '32';
        $draft->firstMonth = '2027-01-01';
        $draft->occurrences = '0';
        $draft->pattern = 'LOY-{AA}';
        $draft->target = 'somewhere';

        self::assertNull($this->check($draft));
        self::assertSame([
            'label' => 'Indiquez l’intitulé de l’abonnement.',
            'amount' => 'Indiquez le montant HT de chaque écriture.',
            'rate' => 'Un taux de TVA s’écrit en pourcentage, de 0 à 100 : 20, 5,5.',
            'day' => 'Un jour du mois va de 1 à 31 (31 : le dernier jour du mois).',
            'firstMonth' => 'Janvier 2027 n’est dans aucun exercice du dossier.',
            'occurrences' => 'Un abonnement compte de 1 à 120 échéances.',
            'pattern' => 'Une pièce ne connaît que {AAAA} (l’année), {MM} (le mois) et {N} (le rang de l’échéance).',
            'target' => 'Choisissez où vont les écritures : saisie en attente ou brouillard.',
        ], $draft->errors);
    }

    public function testAnUnknownTemplateAndMissingFields(): void
    {
        $draft = new SubscriptionDraft();
        $draft->label = 'Abonnement';
        $draft->template = 'XYZ';

        self::assertNull($this->check($draft));
        self::assertSame([
            'template' => 'Le modèle XYZ n’existe pas dans le dossier.',
            'day' => 'Indiquez le jour du mois de chaque écriture.',
            'firstMonth' => 'Choisissez le premier mois de l’abonnement.',
            'occurrences' => 'Indiquez le nombre d’échéances.',
            'pattern' => 'Indiquez la pièce de chaque écriture : LOY-{AAAA}-{MM}.',
            'target' => 'Choisissez où vont les écritures : saisie en attente ou brouillard.',
        ], $draft->errors);
    }

    public function testEachOccurrenceHasItsOwnDocumentOfFortyCharactersAtMost(): void
    {
        $draft = $this->rent();
        $draft->pattern = 'LOYER-{AAAA}';
        self::assertNull($this->check($draft));
        self::assertSame(['pattern' => 'Chaque écriture a sa pièce : mettez {MM} (le mois) ou {N} (le rang) dans la pièce.'], $draft->errors);

        // 30 characters + {AAAA} (4) + {MM} (2) + {N} (2 for 11 occurrences) = 38: accepted.
        $draft->pattern = str_repeat('A', 30).'{AAAA}{MM}{N}';
        self::assertNotNull($this->check($draft));

        // 33 + 4 + 2 + 2 = 41: refused.
        $draft->pattern = str_repeat('A', 33).'{AAAA}{MM}{N}';
        self::assertNull($this->check($draft));
        self::assertSame(['pattern' => 'Une fois remplie, la pièce tient en 40 caractères.'], $draft->errors);

        $draft->pattern = 'LBE {N}/48';
        self::assertNotNull($this->check($draft));
    }

    public function testATemplateThatAsksAThirdPartyNeedsOne(): void
    {
        $draft = $this->rent();
        $draft->label = 'Location de l’entrepôt';
        $draft->template = 'VENTE20';

        self::assertNull($this->check($draft));
        self::assertSame(['thirdParty' => 'Le modèle VENTE20 demande le tiers de l’écriture : indiquez son code auxiliaire.'], $draft->errors);

        $draft->thirdParty = '411xyz';
        self::assertNull($this->check($draft));
        self::assertSame(['thirdParty' => 'Aucun tiers du dossier n’a le code 411XYZ.'], $draft->errors);

        $draft->thirdParty = '411gira';
        self::assertSame('411GIRA', $this->check($draft)?->thirdParty);
    }

    public function testWhatTheTemplateDoesNotAskIsCleared(): void
    {
        $draft = $this->rent();
        $draft->thirdParty = '411GIRA';
        $fields = $this->check($draft);
        self::assertSame('', $fields?->thirdParty);
        self::assertSame('', $draft->thirdParty);

        $draft = $this->rent();
        $draft->template = 'REGLFOUR';
        $draft->target = SubscriptionTarget::Pending->value;
        $fields = $this->check($draft);
        self::assertNotNull($fields);
        self::assertNull($fields->amount);
        self::assertNull($fields->vatRate);
    }

    public function testALineToTypeKeepsTheOccurrencesOutOfTheBrouillard(): void
    {
        $draft = $this->rent();
        $draft->template = 'REGLFOUR';

        self::assertNull($this->check($draft));
        self::assertSame(['target' => 'Le modèle REGLFOUR a une ligne « À saisir » : ses écritures vont en saisie en attente, où le montant se complète dans la grille.'], $draft->errors);
    }

    public function testWhatIsGeneratedStays(): void
    {
        $february = new \DateTimeImmutable('2026-02-01');

        $draft = $this->rent();
        $draft->occurrences = '2';
        self::assertNull(SubscriptionRules::check($draft, $this->templateOf(...), $this->asksThirdParty(...), $this->thirdPartyOf(...), $this->isMonth(...), 3, $february));
        self::assertSame(['occurrences' => '3 échéances sont déjà générées : l’abonnement en compte au moins autant.'], $draft->errors);

        $draft = $this->rent();
        $draft->firstMonth = '2026-03-01';
        self::assertNull(SubscriptionRules::check($draft, $this->templateOf(...), $this->asksThirdParty(...), $this->thirdPartyOf(...), $this->isMonth(...), 3, $february));
        self::assertSame(['firstMonth' => 'Des écritures sont déjà générées depuis février 2026 : le premier mois ne change plus.'], $draft->errors);

        // Before any occurrence, the first month moves freely.
        self::assertNotNull(SubscriptionRules::check($draft, $this->templateOf(...), $this->asksThirdParty(...), $this->thirdPartyOf(...), $this->isMonth(...), 0, $february));
    }

    private function rent(): SubscriptionDraft
    {
        $draft = new SubscriptionDraft();
        $draft->label = 'Loyer de l’atelier';
        $draft->template = 'LOYER';
        $draft->amount = Money::euros('3200.00')->cents;
        $draft->rate = '20';
        $draft->day = '1';
        $draft->firstMonth = '2026-02-01';
        $draft->occurrences = '11';
        $draft->pattern = 'LOY-{AAAA}-{MM}';
        $draft->target = SubscriptionTarget::Draft->value;

        return $draft;
    }

    private function check(SubscriptionDraft $draft): ?SubscriptionFields
    {
        return SubscriptionRules::check($draft, $this->templateOf(...), $this->asksThirdParty(...), $this->thirdPartyOf(...), $this->isMonth(...));
    }

    private function templateOf(string $code): ?EntryTemplate
    {
        return $this->templates[$code] ?? null;
    }

    /** The sale's 411000 is a collective typed by number, without its customer. */
    private function asksThirdParty(EntryTemplate $template): bool
    {
        return 'VENTE20' === $template->getCode();
    }

    private function thirdPartyOf(string $code): ?ThirdParty
    {
        return '411GIRA' === $code ? new ThirdParty($this->templates['VENTE20']->getDossier(), 'C0019', ThirdPartyKind::Customer, 'Girard, Jean et Anne') : null;
    }

    private function isMonth(\DateTimeImmutable $month): bool
    {
        return '2026' === $month->format('Y');
    }

    /** @return array{account: string, thirdParty: string, label: string, side: EntrySide, amount: TemplateAmount, fixed: ?Money} */
    private static function line(string $account, EntrySide $side, TemplateAmount $amount): array
    {
        return ['account' => $account, 'thirdParty' => '', 'label' => '', 'side' => $side, 'amount' => $amount, 'fixed' => null];
    }
}
