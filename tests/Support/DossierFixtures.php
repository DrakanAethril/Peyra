<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Dossier\Company;
use App\Enum\Dossier\LegalForm;
use App\Enum\Dossier\ProfitTaxRegime;
use App\Enum\Dossier\VatRegime;
use App\Service\Shared\Money;

/** A fictitious company for the tests (SavesDossiers saves dossiers). In a file of its own: ParaTest hands out files. */
final class DossierFixtures
{
    public static function company(string $denomination = 'Menuiserie Vasseur'): Company
    {
        return new Company($denomination, LegalForm::Sarl, Money::cents(8_000_000), '99941273600018', 'FR36999412736', '16.23Z', 'Menuiseries bois', 'ZA des Plaines', null, '42600', 'Montbrison', VatRegime::RealNormalMonthly, ProfitTaxRegime::CorporateRealNormal);
    }
}
