<?php

declare(strict_types=1);

namespace App\Tests\Service\Accounting\Template;

use App\Entity\Accounting\Account;
use App\Entity\Accounting\EntryTemplate;
use App\Entity\Accounting\Journal;
use App\Entity\Dossier\Dossier;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Accounting\AccountOrigin;
use App\Enum\Accounting\JournalType;
use App\Enum\Accounting\TemplateBase;
use App\Enum\Dossier\DossierKind;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Service\Accounting\Chart\AccountNumber;
use App\Service\Accounting\Template\GuidedEntry;
use App\Service\Accounting\Template\TemplateCall;
use App\Service\Accounting\Template\TemplateDraft;
use App\Service\Accounting\Template\TemplateFields;
use App\Service\Accounting\Template\TemplateLineDraft;
use App\Service\Accounting\Template\TemplateRules;
use App\Service\Shared\Money;
use App\Tests\Support\DossierFixtures;
use PHPUnit\Framework\TestCase;

/**
 * The reading of an entry template and its call, on entities held in memory: the demonstration's
 * rent (LOYER: 613200 HT, 445660 TVA, 401SCIV TTC; 3 200,00 HT at 20 % makes 3 840,00), its sale
 * at 20 % (411000 asks its customer), its bank fees (one line in BQ2, the counterpart automatic).
 */
final class TemplateRulesTest extends TestCase
{
    private Dossier $dossier;

    /** @var array<string, Account> */
    private array $accounts = [];

    /** @var array<string, Journal> */
    private array $journals = [];

    /** @var array<string, ThirdParty> */
    private array $thirdParties = [];

    protected function setUp(): void
    {
        $this->dossier = new Dossier(DossierKind::Template, DossierFixtures::company(), new \DateTimeImmutable('2026-01-31'), null, null, new \DateTimeImmutable('2026-11-27 10:00'), null);
        foreach (['401000', '411000', '445660', '445710', '512200', '613200', '627800', '701000'] as $number) {
            $this->accounts[$number] = new Account($this->dossier, $number, 'Compte '.$number, AccountNumber::isLettrableByDefault($number), AccountNumber::isCollectiveByDefault($number), AccountOrigin::Reference);
        }
        $this->journals = [
            'ACH' => new Journal($this->dossier, 'ACH', 'Achats', JournalType::Purchases, null),
            'VTE' => new Journal($this->dossier, 'VTE', 'Ventes', JournalType::Sales, null),
            'BQ2' => new Journal($this->dossier, 'BQ2', 'Banque Populaire', JournalType::Bank, $this->accounts['512200']),
            'AN' => new Journal($this->dossier, 'AN', 'À-nouveaux', JournalType::Opening, null),
        ];
        $sci = new ThirdParty($this->dossier, 'F0009', ThirdPartyKind::Supplier, 'SCI Vasseur Immobilier');
        $sci->keepOn($this->accounts['401000'], '401SCIV');
        $girard = new ThirdParty($this->dossier, 'C0019', ThirdPartyKind::Customer, 'Girard, Jean et Anne');
        $girard->keepOn($this->accounts['411000'], '411GIRA');
        $this->thirdParties = ['F0009' => $sci, '401SCIV' => $sci, 'C0019' => $girard, '411GIRA' => $girard];
    }

    public function testTheRentIsATemplate(): void
    {
        $fields = $this->check($this->rent());

        self::assertNotNull($fields);
        self::assertSame('LOYER', $fields->code);
        self::assertSame(TemplateBase::Net, $fields->base);
        self::assertSame('20', $fields->vatRate);
        self::assertSame(['613200', '445660', '401SCIV'], array_column($fields->lines, 'account'));
    }

    public function testTextsAreNormalised(): void
    {
        $draft = $this->rent();
        $draft->code = ' loyer ';
        $draft->rate = '20,00';
        $draft->lines[2]->account = '401sciv';
        $draft->lines[] = new TemplateLineDraft(side: 'debit', amount: 'typed');

        $fields = $this->check($draft);

        self::assertNotNull($fields);
        self::assertSame('LOYER', $fields->code);
        self::assertSame('20', $fields->vatRate);
        self::assertSame('401SCIV', $fields->lines[2]['account']);
        self::assertCount(3, $fields->lines, 'the spare empty line is not a line');
    }

    public function testEveryFaultIsNamedUnderItsField(): void
    {
        $draft = new TemplateDraft();
        $draft->code = 'LOYER-2026';
        $draft->journal = 'AN';
        $draft->base = '';
        $draft->rate = '120';
        $draft->lines = [
            new TemplateLineDraft('613999', '', '', 'debit', 'ht'),
            new TemplateLineDraft('401XYZ', '', '', 'credit', 'ttc'),
            new TemplateLineDraft('445660', 'F0009', '', 'sideways', 'fixed'),
            new TemplateLineDraft('401000', '', '', 'credit', 'balance'),
            new TemplateLineDraft('411000', '', '', 'credit', 'balance'),
        ];

        self::assertNull($this->check($draft));
        self::assertSame([
            'code' => 'Un code de modèle compte de 1 à 12 lettres ou chiffres, sans accent ni espace (LOYER, VENTE20).',
            'label' => 'Indiquez l’intitulé du modèle.',
            'journal' => 'Les à-nouveaux reprennent la balance d’ouverture : ils n’ont pas de modèle.',
            'line.0.account' => 'Le compte 613999 n’est pas dans le plan du dossier.',
            'line.1.account' => 'Ni compte ni tiers ne répond à 401XYZ.',
            'line.2.thirdParty' => 'Seul un compte collectif (401000, 411000) tapé par son numéro prend un tiers.',
            'line.2.side' => 'Choisissez le sens de la ligne.',
            'line.2.fixed' => 'Indiquez le montant fixe de la ligne.',
            'line.4.amount' => 'Une seule ligne prend le solde de l’écriture.',
            'base' => 'Des lignes sont en HT, TVA ou TTC : dites quel montant l’appel demande.',
            'rate' => 'Un taux de TVA s’écrit en pourcentage, de 0 à 100 : 20, 5,5.',
        ], $draft->errors);
    }

    public function testTheCodeIsUniqueInTheDossier(): void
    {
        $draft = $this->rent();

        self::assertNull(TemplateRules::check($draft, $this->journalOf(...), $this->accountOf(...), $this->thirdPartyOf(...), static fn (string $code): ?string => 'LOYER' === $code ? 'Loyer du dépôt' : null));
        self::assertSame('Le modèle LOYER existe déjà : Loyer du dépôt.', $draft->errors['code']);
    }

    public function testUnbalancedLinesAreRefusedWithTheTrialSaid(): void
    {
        // HT on the debit, TTC on the credit, no VAT line: 1 000,00 against 1 200,00.
        $draft = $this->rent();
        array_splice($draft->lines, 1, 1);

        self::assertNull($this->check($draft));
        self::assertSame("Les lignes ne s’équilibrent pas : essayées sur 1\u{202F}000,00 HT, elles font 1\u{202F}000,00 au débit et 1\u{202F}200,00 au crédit. Ajoutez la ligne qui manque, ou une ligne « Solde de l’écriture ».", $draft->errors['lines']);
    }

    public function testALineToTypeOrABalanceLineSparesTheTrial(): void
    {
        $draft = $this->rent();
        $draft->lines[2]->amount = 'balance';
        self::assertNotNull($this->check($draft));

        $draft = $this->rent();
        array_splice($draft->lines, 1, 1);
        $draft->lines[1]->amount = 'typed';
        self::assertNotNull($this->check($draft));
    }

    public function testABankJournalAddsItsOwnCounterpart(): void
    {
        $draft = new TemplateDraft();
        $draft->code = 'FRAISBQ2';
        $draft->label = 'Frais de la Banque Populaire';
        $draft->journal = 'BQ2';
        $draft->lines = [new TemplateLineDraft('627800', '', 'Frais bancaires {mois}', 'debit', 'fixed', 3_200)];
        self::assertNotNull($this->check($draft), 'one line is enough: the counterpart balances it');

        $draft->lines[] = new TemplateLineDraft('512200', '', '', 'credit', 'balance');
        self::assertNull($this->check($draft));
        self::assertSame('La contrepartie 512200 s’ajoute seule dans le journal BQ2 : elle n’entre pas dans le modèle.', $draft->errors['line.1.account']);
    }

    public function testOtherJournalsNeedTwoLines(): void
    {
        $draft = new TemplateDraft();
        $draft->code = 'SEUL';
        $draft->label = 'Une ligne';
        $draft->journal = 'ACH';
        $draft->lines = [new TemplateLineDraft('613200', '', '', 'debit', 'typed')];

        self::assertNull($this->check($draft));
        self::assertSame('Un modèle a deux lignes au moins : une écriture a toujours un débit et un crédit.', $draft->errors['lines']);
    }

    public function testAVatLineNeedsARate(): void
    {
        $draft = $this->rent();
        $draft->rate = '0';

        self::assertNull($this->check($draft));
        self::assertSame('Une ligne de TVA demande un taux supérieur à 0.', $draft->errors['rate']);
    }

    public function testATemplateWithoutHtTvaTtcAsksNoAmount(): void
    {
        $draft = new TemplateDraft();
        $draft->code = 'REGL';
        $draft->label = 'Règlement';
        $draft->journal = 'ACH';
        $draft->base = 'ttc';
        $draft->rate = '20';
        $draft->lines = [new TemplateLineDraft('401000', '', '', 'debit', 'typed'), new TemplateLineDraft('613200', '', '', 'credit', 'balance')];

        $fields = $this->check($draft);

        self::assertNotNull($fields);
        self::assertNull($fields->base);
        self::assertNull($fields->vatRate);
    }

    public function testTheCallOfTheRent(): void
    {
        $template = $this->written($this->check($this->rent()));

        $call = TemplateCall::lines($template, new \DateTimeImmutable('2026-02-01'), Money::cents(320_000), '', '', $this->accountOf(...));

        self::assertSame([], $call['errors']);
        self::assertSame([
            ['account' => '613200', 'thirdParty' => '', 'label' => 'Loyer février 2026', 'debit' => 320_000, 'credit' => 0, 'due' => null],
            ['account' => '445660', 'thirdParty' => '', 'label' => 'Loyer février 2026', 'debit' => 64_000, 'credit' => 0, 'due' => null],
            ['account' => '401SCIV', 'thirdParty' => '', 'label' => 'Loyer de l’atelier', 'debit' => 0, 'credit' => 384_000, 'due' => null],
        ], array_map(static fn ($line): array => $line->toArray(), $call['lines']));
    }

    public function testTheCallSaysWhatIsMissing(): void
    {
        $template = $this->written($this->check($this->rent()));

        $call = TemplateCall::lines($template, new \DateTimeImmutable('2026-02-01'), null, 'vingt', '', $this->accountOf(...));

        self::assertSame(['amount' => 'Indiquez le montant HT.', 'rate' => 'Un taux de TVA s’écrit en pourcentage, de 0 à 100 : 20, 5,5.'], $call['errors']);
    }

    public function testASaleAsksItsCustomer(): void
    {
        $draft = new TemplateDraft();
        $draft->code = 'VENTE20';
        $draft->label = 'Facture de vente à 20 %';
        $draft->journal = 'VTE';
        $draft->base = 'ht';
        $draft->rate = '20';
        $draft->lines = [
            new TemplateLineDraft('411000', '', '', 'debit', 'ttc'),
            new TemplateLineDraft('701000', '', '', 'credit', 'ht'),
            new TemplateLineDraft('445710', '', '', 'credit', 'tva'),
        ];
        $template = $this->written($this->check($draft));

        self::assertTrue(TemplateCall::asksThirdParty($template, $this->accountOf(...)));
        self::assertTrue(GuidedEntry::offers($template));
        $call = TemplateCall::lines($template, new \DateTimeImmutable('2026-01-06'), Money::cents(795_000), '20', '411gira', $this->accountOf(...), true);
        self::assertSame([], $call['errors']);
        self::assertSame(['411000', '411GIRA', 954_000, 0], [$call['lines'][0]->account, $call['lines'][0]->thirdParty, $call['lines'][0]->debit, $call['lines'][0]->credit]);
        self::assertSame([0, 795_000, 0, 159_000], [$call['lines'][1]->debit, $call['lines'][1]->credit, $call['lines'][2]->debit, $call['lines'][2]->credit]);

        $missing = TemplateCall::lines($template, new \DateTimeImmutable('2026-01-06'), Money::cents(795_000), '20', '', $this->accountOf(...), true);
        self::assertSame(['thirdParty' => 'Indiquez le tiers de l’écriture.'], $missing['errors']);
    }

    public function testTheNextDocumentOfASeriesKeepsItsWidth(): void
    {
        self::assertSame('LOY-2026-02', GuidedEntry::nextDocument('LOY-2026-01'));
        self::assertSame('FA-2601-0100', GuidedEntry::nextDocument('FA-2601-0099'));
        self::assertSame('FV9-A', GuidedEntry::nextDocument('FV8-A'));
        self::assertSame('SANS', GuidedEntry::nextDocument('SANS'));
    }

    public function testATemplateWithALineToTypeIsTheGridsOnly(): void
    {
        $draft = $this->rent();
        $draft->lines[2]->amount = 'typed';

        self::assertFalse(GuidedEntry::offers($this->written($this->check($draft))));
    }

    private function rent(): TemplateDraft
    {
        $draft = new TemplateDraft();
        $draft->code = 'LOYER';
        $draft->label = 'Loyer de l’atelier';
        $draft->journal = 'ACH';
        $draft->base = 'ht';
        $draft->rate = '20';
        $draft->lines = [
            new TemplateLineDraft('613200', '', 'Loyer {mois}', 'debit', 'ht'),
            new TemplateLineDraft('445660', '', 'Loyer {mois}', 'debit', 'tva'),
            new TemplateLineDraft('401SCIV', '', '', 'credit', 'ttc'),
        ];

        return $draft;
    }

    private function check(TemplateDraft $draft): ?TemplateFields
    {
        return TemplateRules::check($draft, $this->journalOf(...), $this->accountOf(...), $this->thirdPartyOf(...), static fn (string $code): ?string => null);
    }

    private function written(?TemplateFields $fields): EntryTemplate
    {
        self::assertNotNull($fields);
        $template = new EntryTemplate($this->dossier, $fields->code, $fields->label, $fields->journal);
        $template->describe($fields->code, $fields->label, $fields->journal, $fields->base, $fields->vatRate, $fields->lines);

        return $template;
    }

    private function journalOf(string $code): ?Journal
    {
        return $this->journals[$code] ?? null;
    }

    private function accountOf(string $number): ?Account
    {
        return $this->accounts[$number] ?? null;
    }

    private function thirdPartyOf(string $code): ?ThirdParty
    {
        return $this->thirdParties[$code] ?? null;
    }
}
