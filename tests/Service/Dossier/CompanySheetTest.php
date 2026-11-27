<?php

declare(strict_types=1);

namespace App\Tests\Service\Dossier;

use App\Enum\Dossier\LegalForm;
use App\Service\Audit\AuditTrail;
use App\Service\Dossier\CompanyDraft;
use App\Service\Dossier\CompanySheet;
use App\Tests\Support\DossierFixtures;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/** The fiche société's checks: every fault named under its field, the typed values normalised. */
final class CompanySheetTest extends TestCase
{
    private CompanySheet $sheet;

    protected function setUp(): void
    {
        $this->sheet = new CompanySheet(new AuditTrail($this->createStub(EntityManagerInterface::class), new MockClock()));
    }

    public function testAWellTypedFicheMakesTheCompany(): void
    {
        $draft = CompanyDraft::of(DossierFixtures::company());
        $draft->siret = '999.412.736 00018';
        $draft->vatNumber = 'fr 36 999 412 736';
        $draft->nafCode = '16.23z';
        $draft->city = '  Montbrison  ';

        $company = $this->sheet->check($draft);

        self::assertSame([], $draft->errors);
        self::assertNotNull($company);
        self::assertSame('99941273600018', $company->getSiret());
        self::assertSame('999412736', $company->getSiren());
        self::assertSame('FR36999412736', $company->getVatNumber());
        self::assertSame('16.23Z', $company->getNafCode());
        self::assertSame('Montbrison', $company->getCity());
        self::assertSame(8_000_000, $company->getCapital()->cents);
        self::assertSame('Menuiserie Vasseur SARL', $company->getName());
    }

    public function testEveryFaultIsNamedUnderItsField(): void
    {
        $draft = new CompanyDraft();
        $draft->siret = '1234';
        $draft->vatNumber = 'BE0123456789';
        $draft->nafCode = '1623';
        $draft->postalCode = '426';
        $draft->capitalCents = -100;
        $draft->legalForm = 'sarl';

        self::assertNull($this->sheet->check($draft));
        self::assertSame(
            ['denomination', 'capital', 'siret', 'vatNumber', 'nafCode', 'activity', 'street', 'postalCode', 'city', 'vatRegime', 'profitTaxRegime'],
            array_keys($draft->errors),
        );
        self::assertSame('Le SIRET compte 14 chiffres.', $draft->error('siret'));
        self::assertSame('Le capital ne peut pas être négatif.', $draft->error('capital'));
    }

    public function testTheVatNumberCarriesTheSiren(): void
    {
        $draft = CompanyDraft::of(DossierFixtures::company());
        $draft->vatNumber = 'FR36123456789';

        self::assertNull($this->sheet->check($draft));
        self::assertSame(['vatNumber'], array_keys($draft->errors));
    }

    public function testAnEntrepriseIndividuelleHasNoCapital(): void
    {
        $draft = CompanyDraft::of(DossierFixtures::company());
        $draft->legalForm = LegalForm::Ei->value;
        $draft->capitalCents = null;

        $company = $this->sheet->check($draft);

        self::assertNotNull($company);
        self::assertTrue($company->getCapital()->isZero());
    }

    public function testACompanyNeedsItsCapital(): void
    {
        $draft = CompanyDraft::of(DossierFixtures::company());
        $draft->capitalCents = null;

        self::assertNull($this->sheet->check($draft));
        self::assertSame('Indiquez le capital social.', $draft->error('capital'));
    }
}
