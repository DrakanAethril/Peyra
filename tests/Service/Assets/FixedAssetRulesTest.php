<?php

declare(strict_types=1);

namespace App\Tests\Service\Assets;

use App\Entity\Accounting\Account;
use App\Entity\Dossier\Dossier;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Accounting\AccountOrigin;
use App\Enum\Assets\DepreciationMethod;
use App\Enum\Dossier\DossierKind;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Service\Accounting\Chart\AccountNumber;
use App\Service\Assets\FixedAssetDraft;
use App\Service\Assets\FixedAssetRules;
use App\Service\Assets\FixedAssetValues;
use App\Tests\Support\DossierFixtures;
use PHPUnit\Framework\TestCase;

/** The reading of a fixed asset's fiche (step 3.4), on accounts and third parties held in memory. */
final class FixedAssetRulesTest extends TestCase
{
    /** @var array<int|string, Account> account numbers are numeric strings, hence integer keys */
    private array $accounts = [];

    /** @var array<string, ThirdParty> */
    private array $thirdParties = [];

    protected function setUp(): void
    {
        $dossier = new Dossier(DossierKind::Template, DossierFixtures::company(), new \DateTimeImmutable('2026-03-31'), null, null, new \DateTimeImmutable('2026-11-28 10:00'), null);
        foreach (['215400', '281540', '207000', '401000', '404000', '411000', '512100'] as $number) {
            $this->accounts[$number] = new Account($dossier, $number, 'Compte '.$number, AccountNumber::isLettrableByDefault($number), AccountNumber::isCollectiveByDefault($number), AccountOrigin::Reference);
        }
        $this->thirdParties['F0019'] = new ThirdParty($dossier, 'F0019', ThirdPartyKind::Supplier, 'Machines Bois Industrie');
        $this->thirdParties['C0001'] = new ThirdParty($dossier, 'C0001', ThirdPartyKind::Customer, 'Un client');
    }

    public function testTheMachiningCentreByComponents(): void
    {
        $values = $this->check($this->machiningCentre());

        self::assertNotNull($values);
        self::assertSame(['I13', 8_650_000, '281540', 'F0019', null], [$values->code, $values->price->plus($values->incidentalCosts)->cents, $values->depreciationAccount?->getNumber(), $values->supplier?->getCode(), $values->years]);
        self::assertSame([['label' => 'Structure', 'cost' => 7_450_000, 'years' => 10], ['label' => 'Broche', 'cost' => 1_200_000, 'years' => 5]], $values->components);
    }

    public function testComponentsAddUpToTheCost(): void
    {
        $draft = $this->machiningCentre();
        $draft->components[1]['cost'] = 1_100_000;

        self::assertNull($this->check($draft));
        self::assertSame("Les composants font 85\u{202F}500,00 : ils font ensemble le coût d’entrée, 86\u{202F}500,00.", $draft->errors['components']);
    }

    public function testEveryFaultIsNamedUnderItsField(): void
    {
        $draft = new FixedAssetDraft();
        $draft->code = 'I-13';
        $draft->account = '281540';
        $draft->acquiredOn = '2026-03-16';
        $draft->inServiceOn = '2026-03-01';
        $draft->price = 0;
        $draft->supplier = 'C0001';
        $draft->method = 'declining';
        $draft->years = '2';
        $draft->fiscalDeclining = true;

        self::assertNull($this->check($draft));
        self::assertSame([
            'code' => 'Un code d’immobilisation compte de 1 à 12 lettres ou chiffres, sans accent ni espace (I01, MAT2026).',
            'label' => 'Indiquez l’intitulé de l’immobilisation.',
            'account' => 'Une immobilisation se tient sur un compte de la classe 2 (hors 28 et 29).',
            'depreciationAccount' => 'Indiquez le compte d’amortissement.',
            'inServiceOn' => 'Une immobilisation est mise en service le jour de son acquisition ou après.',
            'price' => 'Indiquez le prix d’acquisition hors taxes.',
            'supplier' => 'Le tiers C0001 n’est pas un fournisseur.',
            'years' => 'Le dégressif demande une durée d’utilisation de 3 ans au moins.',
            'fiscalDeclining' => 'Le plan fiscal dégressif accompagne un amortissement linéaire de 3 ans au moins.',
        ], $draft->errors);
    }

    public function testAGoodwillIsNotDepreciated(): void
    {
        $draft = new FixedAssetDraft();
        $draft->code = 'I01';
        $draft->label = 'Fonds commercial';
        $draft->account = '207000';
        $draft->depreciationAccount = '281540';
        $draft->acquiredOn = '1994-03-14';
        $draft->inServiceOn = '1994-03-14';
        $draft->price = 3_800_000;
        $draft->method = 'none';
        $draft->years = '10';

        $values = $this->check($draft);

        self::assertNotNull($values);
        self::assertSame([DepreciationMethod::None, null, null], [$values->method, $values->depreciationAccount, $values->years]);
    }

    public function testTheResidualValueStaysBelowTheCostAndAwayFromTheDecliningMethod(): void
    {
        $draft = $this->machine('linear', '5');
        $draft->residualValue = 1_000_000;
        self::assertNull($this->check($draft));
        self::assertSame("La valeur résiduelle reste sous le coût d’entrée (10\u{202F}000,00).", $draft->errors['residualValue']);

        $draft = $this->machine('declining', '5');
        $draft->residualValue = 100_000;
        self::assertNull($this->check($draft));
        self::assertSame('Le dégressif amortit jusqu’à zéro : pas de valeur résiduelle.', $draft->errors['residualValue']);
    }

    public function testUnitsOfWork(): void
    {
        $draft = $this->machine('units', '');
        $draft->totalUnits = '100 000';
        $draft->unitsUsed = ['2026-12-31' => '18000', '2027-12-31' => ''];

        $values = $this->check($draft);

        self::assertNotNull($values);
        self::assertSame([100_000, ['2026-12-31' => 18_000]], [$values->totalUnits, $values->unitsUsed]);
    }

    public function testTheDepreciationAccountProposed(): void
    {
        self::assertSame('281540', FixedAssetRules::depreciationAccountFor('215400'));
        self::assertSame('280500', FixedAssetRules::depreciationAccountFor('205000'));
        self::assertSame('281810', FixedAssetRules::depreciationAccountFor('218100'));
        // A plan of the PCG stops at 2815: 281500 is proposed.
        $accountOf = static fn (string $number): ?Account => '281500' === $number ? new Account(new Dossier(DossierKind::Template, DossierFixtures::company(), new \DateTimeImmutable('2026-03-31'), null, null, new \DateTimeImmutable('2026-11-28 10:00'), null), '281500', 'Amortissements', false, false, AccountOrigin::Reference) : null;
        self::assertSame('281500', FixedAssetRules::proposedDepreciationAccount('215400', $accountOf));
        self::assertNull(FixedAssetRules::proposedDepreciationAccount('207000', static fn (): null => null));
    }

    public function testAnImpairmentIsNeverNegativeNorOnComponents(): void
    {
        $draft = $this->machine('linear', '5');
        $draft->impairments = ['2026-12-31' => 120_000, '2025-12-31' => null];
        self::assertSame(['2026-12-31' => 120_000], $this->check($draft)?->impairments);

        $draft->impairments = ['2026-12-31' => -1];
        self::assertNull($this->check($draft));
        self::assertSame('Une dépréciation n’est jamais négative.', $draft->errors['impairments']);

        $centre = $this->machiningCentre();
        $centre->impairments = ['2026-12-31' => 50_000];
        self::assertNull($this->check($centre));
        self::assertArrayHasKey('impairments', $centre->errors);
    }

    private function machiningCentre(): FixedAssetDraft
    {
        $draft = $this->machine('linear', '');
        $draft->code = 'i13';
        $draft->supplier = 'f0019';
        $draft->price = 8_400_000;
        $draft->incidentalCosts = 250_000;
        $draft->components = [['label' => 'Structure', 'cost' => 7_450_000, 'years' => '10'], ['label' => 'Broche', 'cost' => 1_200_000, 'years' => '5'], ['label' => '', 'cost' => null, 'years' => '']];

        return $draft;
    }

    private function machine(string $method, string $years): FixedAssetDraft
    {
        $draft = new FixedAssetDraft();
        $draft->code = 'M01';
        $draft->label = 'Machine';
        $draft->account = '215400';
        $draft->depreciationAccount = '281540';
        $draft->acquiredOn = '2026-03-16';
        $draft->inServiceOn = '2026-04-01';
        $draft->price = 1_000_000;
        $draft->method = $method;
        $draft->years = $years;

        return $draft;
    }

    private function check(FixedAssetDraft $draft): ?FixedAssetValues
    {
        return FixedAssetRules::check($draft, fn (string $number): ?Account => $this->accounts[$number] ?? null, fn (string $code): ?ThirdParty => $this->thirdParties[$code] ?? null, static fn (): null => null);
    }
}
