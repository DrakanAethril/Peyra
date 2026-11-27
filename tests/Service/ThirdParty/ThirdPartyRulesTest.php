<?php

declare(strict_types=1);

namespace App\Tests\Service\ThirdParty;

use App\Entity\Accounting\Account;
use App\Entity\Dossier\Dossier;
use App\Enum\Accounting\AccountOrigin;
use App\Enum\Dossier\DossierKind;
use App\Enum\ThirdParty\PaymentMethod;
use App\Enum\ThirdParty\PaymentTermKind;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Service\ThirdParty\ThirdPartyDraft;
use App\Service\ThirdParty\ThirdPartyRules;
use App\Tests\Support\DossierFixtures;
use PHPUnit\Framework\TestCase;

/** What makes a third party valid (ThirdPartyRules), field by field, over a small plan. */
final class ThirdPartyRulesTest extends TestCase
{
    /** @var array<string, Account> */
    private array $plan = [];

    protected function setUp(): void
    {
        $dossier = new Dossier(DossierKind::Template, DossierFixtures::company(), new \DateTimeImmutable('2026-01-01'), null, null, new \DateTimeImmutable('2026-11-27'), null);
        foreach ([['401000', true], ['404000', true], ['411000', true], ['421000', false], ['431000', false], ['164100', false], ['512100', false], ['445660', false]] as [$number, $collective]) {
            $this->plan[$number] = new Account($dossier, $number, 'Compte '.$number, true, $collective, AccountOrigin::Reference);
        }
    }

    public function testASupplierOnItsCollectiveWithItsAuxiliaryCode(): void
    {
        $draft = $this->draft(['code' => ' f0001 ', 'kind' => 'supplier', 'name' => '  Scierie  du Haut-Forez ', 'account' => '401 000', 'auxiliaryCode' => '401scie', 'siren' => '512 904 337', 'vatNumber' => 'fr 41 512904337', 'country' => '', 'termKind' => 'net', 'termDays' => '30', 'termDay' => '12']);
        $fields = $this->check($draft);

        self::assertNotNull($fields, implode(' ', $draft->errors));
        self::assertSame(['F0001', 'Scierie du Haut-Forez', '401000', '401SCIE', '512904337', 'FR41512904337', 'FR', ''], [$draft->code, $draft->name, $draft->account, $draft->auxiliaryCode, $draft->siren, $draft->vatNumber, $draft->country, $draft->termDay]);
        self::assertSame(ThirdPartyKind::Supplier, $fields->kind);
        self::assertSame('401000', $fields->account?->getNumber());
        self::assertSame('401SCIE', $fields->auxiliaryCode);
        self::assertSame('30 jours date de facture', $fields->terms->label());
        self::assertSame(PaymentMethod::Transfer, $fields->method);
        self::assertNull($draft->notice);
    }

    public function testAnAuxiliaryCodeIsGivenWhenNoneIsTyped(): void
    {
        $draft = $this->draft(['code' => 'F0099', 'kind' => 'supplier', 'name' => 'Scierie Martin', 'account' => '401000']);
        $fields = $this->check($draft, auxiliaries: ['401SCIE' => 'Scierie du Haut-Forez']);

        self::assertSame('401SCIE2', $fields?->auxiliaryCode);
        self::assertSame('Code auxiliaire 401SCIE2 attribué sur le compte 401000.', $draft->notice);
    }

    public function testAGeneralAccountTakesNoAuxiliaryCodeAndAThirdPartyMayHaveNoAccount(): void
    {
        $draft = $this->draft(['code' => 'O0001', 'kind' => 'other', 'name' => 'Urssaf', 'account' => '431000']);
        self::assertNull($this->check($draft)?->auxiliaryCode);

        $draft = $this->draft(['code' => 'O0001', 'kind' => 'other', 'name' => 'Urssaf', 'account' => '431000', 'auxiliaryCode' => '431URSS']);
        self::assertNull($this->check($draft));
        self::assertSame(['auxiliaryCode' => 'Seul un tiers tenu sur un compte collectif (401000, 411000) a un code auxiliaire.'], $draft->errors);

        $draft = $this->draft(['code' => 'F0021', 'kind' => 'supplier', 'name' => 'Station U', 'termKind' => 'immediate', 'paymentMethod' => 'card']);
        $fields = $this->check($draft);
        self::assertNotNull($fields);
        self::assertNull($fields->account);
        self::assertSame(PaymentTermKind::Immediate, $fields->terms->getKind());
    }

    public function testTheAccountFitsTheKind(): void
    {
        $cases = [
            ['customer', '401000', 'Le compte d’un client est un compte de clients (411000, 416000…).'],
            ['supplier', '411000', 'Le compte d’un fournisseur est un compte de fournisseurs (401000, 404000…).'],
            ['employee', '431000', 'Le compte d’un salarié est un compte de personnel (421000…).'],
            ['other', '512100', 'Le compte d’un autre tiers est un compte de tiers de la classe 4 ou un emprunt (16…).'],
            ['customer', '418000', 'Le compte 418000 n’est pas dans le plan du dossier.'],
            ['customer', '411SCIE', 'Indiquez ici le compte collectif (401000) ; le code auxiliaire (401SCIE) a son propre champ.'],
        ];
        foreach ($cases as [$kind, $account, $error]) {
            $draft = $this->draft(['code' => 'X1', 'kind' => $kind, 'name' => 'Tiers', 'account' => $account]);
            self::assertNull($this->check($draft));
            self::assertSame(['account' => $error], $draft->errors, $kind.' '.$account);
        }
        self::assertNotNull($this->check($this->draft(['code' => 'E0001', 'kind' => 'other', 'name' => 'Banque Populaire - emprunt', 'account' => '164100'])), 'Un prêteur est tenu sur son emprunt.');
        self::assertNotNull($this->check($this->draft(['code' => 'F0019', 'kind' => 'supplier', 'name' => 'Machines Bois Industrie', 'account' => '404000', 'auxiliaryCode' => '404MBIN'])));
    }

    public function testEveryFaultIsNamedUnderItsField(): void
    {
        $draft = $this->draft([
            'code' => 'C-1', 'kind' => 'friend', 'name' => '', 'siren' => '1234', 'vatNumber' => 'FR12999999999', 'country' => 'France',
            'postalCode' => '4260', 'email' => 'pas une adresse', 'phone' => 'joindre Paul', 'termKind' => 'weekly', 'paymentMethod' => 'troc', 'note' => str_repeat('a', 501),
        ]);
        self::assertNull($this->check($draft));
        self::assertSame([
            'code' => 'Un code de tiers compte de 1 à 12 lettres ou chiffres, sans accent (C0003, F0012).',
            'kind' => 'Choisissez la nature du tiers.',
            'name' => 'Indiquez le nom du tiers.',
            'siren' => 'Un SIREN compte 9 chiffres.',
            'country' => 'Le pays s’écrit en deux lettres (FR, DE, BE).',
            'email' => 'Cette adresse électronique n’est pas valable (nom@domaine.fr).',
            'phone' => 'Un numéro de téléphone s’écrit avec des chiffres, des espaces et +.',
            'termKind' => 'Choisissez les conditions de règlement.',
            'paymentMethod' => 'Choisissez le mode de règlement.',
            'note' => 'Une note tient en 500 caractères.',
        ], $draft->errors);
    }

    public function testIdentityAndAddressRules(): void
    {
        $individual = $this->draft(['code' => 'C0019', 'kind' => 'customer', 'name' => 'Girard', 'individual' => true, 'siren' => '999104332', 'vatNumber' => 'FR10999104332']);
        self::assertNull($this->check($individual));
        self::assertSame(['siren' => 'Un particulier n’a pas de SIREN.', 'vatNumber' => 'Un particulier n’a pas de numéro de TVA intracommunautaire.'], $individual->errors);

        $mismatch = $this->draft(['code' => 'C0003', 'kind' => 'customer', 'name' => 'Bâtir', 'siren' => '999104332', 'vatNumber' => 'FR10999104333', 'postalCode' => '4200']);
        self::assertNull($this->check($mismatch));
        self::assertSame(['vatNumber' => 'Le numéro de TVA reprend le SIREN du tiers.', 'postalCode' => 'Un code postal français compte 5 chiffres.'], $mismatch->errors);

        $foreign = $this->draft(['code' => 'F0006', 'kind' => 'supplier', 'name' => 'Hartmann Beschläge', 'vatNumber' => 'DE999999999', 'postalCode' => 'H2X 1Y4', 'country' => 'de']);
        self::assertNotNull($this->check($foreign), implode(' ', $foreign->errors));
        self::assertSame('DE', $foreign->country);

        $badForeign = $this->draft(['code' => 'F0006', 'kind' => 'supplier', 'name' => 'Hartmann', 'vatNumber' => 'D9']);
        self::assertNull($this->check($badForeign));
        self::assertSame(['vatNumber' => 'Un numéro de TVA intracommunautaire s’écrit le code du pays (DE, IT…) suivi de 2 à 13 lettres ou chiffres.'], $badForeign->errors);
    }

    public function testTermsNeedTheirNumber(): void
    {
        $draft = $this->draft(['code' => 'C1', 'kind' => 'customer', 'name' => 'Tiers', 'termKind' => 'end_of_month_net', 'termDays' => '']);
        self::assertNull($this->check($draft));
        self::assertSame(['termDays' => 'Indiquez un nombre de jours, de 1 à 120.'], $draft->errors);

        $draft = $this->draft(['code' => 'C1', 'kind' => 'customer', 'name' => 'Tiers', 'termKind' => 'day_of_next_month', 'termDays' => '30', 'termDay' => '32']);
        self::assertNull($this->check($draft));
        self::assertSame(['termDay' => 'Indiquez le jour du mois, de 1 à 31.'], $draft->errors);

        $draft = $this->draft(['code' => 'C1', 'kind' => 'customer', 'name' => 'Tiers', 'termKind' => 'day_of_next_month', 'termDays' => '30', 'termDay' => '10']);
        self::assertSame('Le 10 du mois suivant', $this->check($draft)?->terms->label());
        self::assertSame('', $draft->termDays, 'Le nombre de jours que la condition n’utilise pas est effacé.');
    }

    public function testCodesHeldByAnotherAreRefused(): void
    {
        $draft = $this->draft(['code' => 'F0001', 'kind' => 'supplier', 'name' => 'Autre scierie', 'account' => '401000', 'auxiliaryCode' => '401SCIE']);
        self::assertNull($this->check($draft, ['F0001' => 'Scierie du Haut-Forez'], ['401SCIE' => 'Scierie du Haut-Forez']));
        self::assertSame([
            'code' => 'Le code F0001 est déjà celui de Scierie du Haut-Forez.',
            'auxiliaryCode' => 'Le code auxiliaire 401SCIE est déjà celui de Scierie du Haut-Forez.',
        ], $draft->errors);
    }

    /**
     * @param array<string, string> $codes       code → name of who holds it
     * @param array<string, string> $auxiliaries auxiliary code → name of who holds it
     */
    private function check(ThirdPartyDraft $draft, array $codes = [], array $auxiliaries = []): ?\App\Service\ThirdParty\ThirdPartyFields
    {
        return ThirdPartyRules::check(
            $draft,
            fn (string $number): ?Account => $this->plan[$number] ?? null,
            static fn (string $code): ?string => $codes[$code] ?? null,
            static fn (string $code): ?string => $auxiliaries[$code] ?? null,
        );
    }

    /** @param array<string, string|bool> $fields */
    private function draft(array $fields): ThirdPartyDraft
    {
        $draft = new ThirdPartyDraft();
        $draft->termKind = 'net';
        $draft->termDays = '30';
        $draft->paymentMethod = 'transfer';
        foreach ($fields as $field => $value) {
            if ('individual' === $field) {
                $draft->individual = true === $value;
            } else {
                $draft->{$field} = (string) $value;
            }
        }

        return $draft;
    }
}
