<?php

declare(strict_types=1);

namespace App\Tests\Service\Dossier\Rights;

use App\Enum\Accounting\JournalType;
use App\Enum\Dossier\AccessLevel;
use App\Enum\Dossier\DossierArea;
use App\Enum\Dossier\DossierProfile;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Service\Dossier\Rights\Gesture;
use App\Service\Dossier\Rights\ProfileMatrix;
use PHPUnit\Framework\TestCase;

/**
 * The rights of each profile area by area (lot 1, step 1.7; DECISIONS 1.7), written here by hand
 * from the decision - never copied from the code: the accountants validate their own journal and
 * enter the payments, the payroll manager enters the OD, the chief accountant validates all and
 * alone changes the settings; no profile, every right.
 */
final class ProfileMatrixTest extends TestCase
{
    private const array EXPECTED = [
        // area => [supplier, customer, payroll, chief]
        'purchases' => ['Validation', 'Fermé', 'Fermé', 'Validation'],
        'sales' => ['Fermé', 'Validation', 'Fermé', 'Validation'],
        'treasury' => ['Saisie', 'Saisie', 'Consultation', 'Validation'],
        'general' => ['Consultation', 'Consultation', 'Saisie', 'Validation'],
        'settings' => ['Consultation', 'Consultation', 'Consultation', 'Modification'],
        // Step 4.1: the payroll is the payroll manager's and the chief accountant's alone.
        'payroll' => ['Fermé', 'Fermé', 'Validation', 'Validation'],
    ];

    public function testTheMatrixAsDecided(): void
    {
        $profiles = [DossierProfile::SupplierAccountant, DossierProfile::CustomerAccountant, DossierProfile::PayrollManager, DossierProfile::ChiefAccountant];
        // Every area has its row: a new area without one is an offset PHPStan refuses.
        foreach (DossierArea::cases() as $area) {
            $labels = self::EXPECTED[$area->value];
            $read = array_map(static fn (DossierProfile $profile): string => ProfileMatrix::level($profile, $area)->labelIn($area), $profiles);
            self::assertSame($labels, $read, $area->label());
        }
    }

    public function testWithoutAProfileEveryRight(): void
    {
        foreach (DossierArea::cases() as $area) {
            self::assertSame($area->topLevel(), ProfileMatrix::level(null, $area));
        }
        self::assertSame(AccessLevel::Enter, DossierArea::Settings->topLevel(), 'Settings are changed, never validated.');
    }

    public function testEachLevelHoldsTheOnesBelow(): void
    {
        self::assertTrue(AccessLevel::Validate->allows(AccessLevel::Enter));
        self::assertTrue(AccessLevel::Enter->allows(AccessLevel::Read));
        self::assertFalse(AccessLevel::Read->allows(AccessLevel::Enter));
        self::assertFalse(AccessLevel::Closed->allows(AccessLevel::Read));
    }

    public function testTheRefusalInTheWordsOfTheDesign(): void
    {
        // design-ux.md § 6.1, word for word.
        self::assertSame(
            'Votre profil “Comptable fournisseurs” ne permet pas de valider le journal de banque.',
            ProfileMatrix::refusal(DossierProfile::SupplierAccountant, DossierArea::Treasury, AccessLevel::Validate, Gesture::journal(JournalType::Bank, AccessLevel::Validate)),
        );
        self::assertSame(
            'Votre profil “Comptable clients” ne permet pas de saisir dans le journal d’achats.',
            ProfileMatrix::refusal(DossierProfile::CustomerAccountant, DossierArea::Purchases, AccessLevel::Enter, Gesture::journal(JournalType::Purchases, AccessLevel::Enter)),
        );
        self::assertNull(ProfileMatrix::refusal(DossierProfile::SupplierAccountant, DossierArea::Purchases, AccessLevel::Validate, 'valider'));
        self::assertNull(ProfileMatrix::refusal(null, DossierArea::Settings, AccessLevel::Enter, 'modifier'));
    }

    public function testJournalsAndLetteringsFallInTheirArea(): void
    {
        self::assertSame(DossierArea::Purchases, DossierArea::ofJournal(JournalType::Purchases));
        self::assertSame(DossierArea::Sales, DossierArea::ofJournal(JournalType::Sales));
        self::assertSame(DossierArea::Treasury, DossierArea::ofJournal(JournalType::Bank));
        self::assertSame(DossierArea::Treasury, DossierArea::ofJournal(JournalType::Cash));
        self::assertSame(DossierArea::General, DossierArea::ofJournal(JournalType::General));
        self::assertSame(DossierArea::General, DossierArea::ofJournal(JournalType::Opening));
        self::assertSame(DossierArea::Purchases, DossierArea::ofLettering(ThirdPartyKind::Supplier));
        self::assertSame(DossierArea::Sales, DossierArea::ofLettering(ThirdPartyKind::Customer));
        self::assertSame(DossierArea::General, DossierArea::ofLettering(ThirdPartyKind::Other));
        self::assertSame(DossierArea::General, DossierArea::ofLettering(null), 'A general account (467000) is lettered with the OD.');
    }
}
