<?php

declare(strict_types=1);

namespace App\Tests\Service\Payroll;

use App\Entity\Dossier\Dossier;
use App\Entity\Payroll\PayrollBody;
use App\Enum\Payroll\PayrollBase;
use App\Enum\Payroll\PayrollCeiling;
use App\Enum\Payroll\PayrollItemKind;
use App\Enum\Payroll\PayrollRateSource;
use App\Enum\Payroll\PayslipLine;
use App\Service\Payroll\Plan\PayrollItemDraft;
use App\Service\Payroll\Plan\PayrollItemRules;
use PHPUnit\Framework\TestCase;

/** What makes a rubrique of the plan de paie (lot 4, step 4.1), field by field, written by hand from the rules. */
final class PayrollItemRulesTest extends TestCase
{
    private PayrollBody $urssaf;

    protected function setUp(): void
    {
        $dossier = (new \ReflectionClass(Dossier::class))->newInstanceWithoutConstructor();
        $this->urssaf = new PayrollBody($dossier, \App\Enum\Payroll\PayrollBodyKind::Urssaf, 'Urssaf', '');
    }

    public function testACappedContributionWithBothSides(): void
    {
        $draft = $this->contribution();
        $spec = PayrollItemRules::check($draft, $this->urssaf);

        self::assertNotNull($spec, implode(' ', $draft->errors));
        self::assertSame('2200', $spec->code);
        self::assertSame(2200, $spec->sortOrder, 'Without an order, the code’s number.');
        self::assertSame(PayrollCeiling::Tranche1, $spec->ceiling);
        self::assertSame('social.contrib.old_age_capped', $spec->employeeRate->getReferenceKey());
        self::assertSame(['employee_rate'], $spec->employeeRate->pathSegments());
        self::assertSame(PayslipLine::SocialSecurityCapped, $spec->line);
    }

    public function testAPathIntoAListReadsItsPositionAsANumber(): void
    {
        $draft = $this->contribution();
        $draft->employeePath = '0.employee_rate';

        self::assertSame([0, 'employee_rate'], PayrollItemRules::check($draft, $this->urssaf)?->employeeRate->pathSegments());
    }

    public function testADossierRateIsAnExactPercent(): void
    {
        $draft = $this->contribution();
        [$draft->employerSource, $draft->employerKey, $draft->employerPath, $draft->employerValue] = ['fixed', '', '', '1,50'];

        self::assertSame('1.5', PayrollItemRules::check($draft, $this->urssaf)?->employerRate->getPercent());

        $draft->employerValue = '120';
        self::assertNull(PayrollItemRules::check($draft, $this->urssaf));
        self::assertSame('Un taux est compris entre 0 et 100 %.', $draft->error('employerValue'));

        $draft->employerValue = '0,01625';
        self::assertNull(PayrollItemRules::check($draft, $this->urssaf));
        self::assertSame('Un taux a quatre décimales au plus (0,0160).', $draft->error('employerValue'));
    }

    public function testAFlatContributionIsAnAmountOnEachSide(): void
    {
        $draft = $this->contribution();
        $draft->base = PayrollBase::Flat->value;
        $draft->ceiling = 'none';
        [$draft->employeeSource, $draft->employeeKey, $draft->employeePath, $draft->employeeValue] = ['amount', '', '', '28,00'];
        [$draft->employerSource, $draft->employerKey, $draft->employerPath, $draft->employerValue] = ['amount', '', '', '28,00'];

        $spec = PayrollItemRules::check($draft, $this->urssaf);
        self::assertSame(2800, $spec?->employeeRate->getAmount()?->cents);

        $draft->employerSource = 'regulatory';
        self::assertNull(PayrollItemRules::check($draft, $this->urssaf));
        self::assertNotNull($draft->error('employerSource'), 'A flat contribution has no regulatory rate.');
    }

    public function testAContributionNeedsARateItsBodyAndItsLine(): void
    {
        $draft = $this->contribution();
        [$draft->employeeSource, $draft->employerSource] = ['none', 'none'];
        $draft->line = '';

        self::assertNull(PayrollItemRules::check($draft, null));
        self::assertSame('Une cotisation a un taux salarial, un taux patronal ou les deux.', $draft->error('employeeSource'));
        self::assertSame('Indiquez l’organisme à qui la cotisation est versée.', $draft->error('body'));
        self::assertSame('Indiquez la ligne du bulletin clarifié qui regroupe la cotisation.', $draft->error('line'));
    }

    public function testOnlyTheGrossSalaryTakesACeiling(): void
    {
        $draft = $this->contribution();
        $draft->base = PayrollBase::CsgBase->value;

        self::assertNull(PayrollItemRules::check($draft, $this->urssaf));
        self::assertSame('Seul le salaire brut se plafonne : choisissez « Sans plafond ».', $draft->error('ceiling'));
    }

    public function testAGrossElementHasNoEmployerSideNorBody(): void
    {
        $draft = new PayrollItemDraft();
        [$draft->code, $draft->label, $draft->kind, $draft->base] = ['1100', 'Heures supplémentaires à 25 %', PayrollItemKind::Gross->value, PayrollBase::EnteredHours->value];
        [$draft->employeeSource, $draft->employeeKey, $draft->employeePath] = [PayrollRateSource::HourlyRateWithPremium->value, 'social.overtime.premium', 'hours_36_to_43'];
        self::assertNotNull(PayrollItemRules::check($draft, null), implode(' ', $draft->errors));

        [$draft->employerSource, $draft->employerKey, $draft->employerPath] = ['regulatory', 'social.contrib.csa', 'employer_rate'];
        $draft->line = PayslipLine::Family->value;
        self::assertNull(PayrollItemRules::check($draft, $this->urssaf));
        self::assertSame('Seule une cotisation a une part patronale.', $draft->error('employerSource'));
        self::assertSame('Seule une cotisation est versée à un organisme.', $draft->error('body'));
        self::assertSame('Seule une cotisation figure sur une ligne du bulletin clarifié.', $draft->error('line'));
    }

    public function testHoursNeedTheirRateAndAnEnteredAmountNone(): void
    {
        $draft = new PayrollItemDraft();
        [$draft->code, $draft->label, $draft->kind, $draft->base] = ['1200', 'Absence non rémunérée', PayrollItemKind::Gross->value, PayrollBase::EnteredHours->value];
        $draft->subtracts = true;
        self::assertNull(PayrollItemRules::check($draft, null));
        self::assertSame('Une rubrique sur « heures du mois » a son taux.', $draft->error('employeeSource'));

        $draft->employeeSource = PayrollRateSource::HourlyRate->value;
        self::assertTrue(PayrollItemRules::check($draft, null)?->subtracts);

        [$draft->base, $draft->employeeSource] = [PayrollBase::EnteredAmount->value, PayrollRateSource::Fixed->value];
        $draft->employeeValue = '10';
        self::assertNull(PayrollItemRules::check($draft, null));
        self::assertNotNull($draft->error('employeeSource'), 'An amount entered in the month takes no rate.');
    }

    public function testADeductionNeitherSubtractsNorStandsOnTheGross(): void
    {
        $draft = new PayrollItemDraft();
        [$draft->code, $draft->label, $draft->kind, $draft->base] = ['4200', 'Prélèvement à la source', PayrollItemKind::Deduction->value, PayrollBase::Gross->value];
        $draft->subtracts = true;

        self::assertNull(PayrollItemRules::check($draft, null));
        self::assertSame('Une rubrique « retenue » ne repose pas sur « salaire brut ».', $draft->error('base'));
        self::assertNotNull($draft->error('subtracts'));

        [$draft->base, $draft->subtracts, $draft->employeeSource] = [PayrollBase::NetTaxable->value, false, PayrollRateSource::WithholdingRate->value];
        self::assertNotNull(PayrollItemRules::check($draft, null), implode(' ', $draft->errors));
    }

    public function testCodeKeyAndPathAreWrittenAsSaid(): void
    {
        $draft = $this->contribution();
        [$draft->code, $draft->employeeKey, $draft->employerKey, $draft->employerPath] = ['220', 'fiscal.vat.rates', 'social contrib', 'Employer-Rate'];

        self::assertNull(PayrollItemRules::check($draft, $this->urssaf));
        self::assertSame('Un code de rubrique compte quatre chiffres (2200).', $draft->error('code'));
        self::assertSame('Une clé de valeur sociale s’écrit social.nom (social.contrib.health).', $draft->error('employeeKey'));
        self::assertNotNull($draft->error('employerKey'));
        self::assertNotNull($draft->error('employerPath'));
    }

    private function contribution(): PayrollItemDraft
    {
        $draft = new PayrollItemDraft();
        [$draft->code, $draft->label, $draft->kind, $draft->base, $draft->ceiling] = ['2200', 'Vieillesse plafonnée', 'contribution', 'gross', 'tranche_1'];
        [$draft->employeeSource, $draft->employeeKey, $draft->employeePath] = ['regulatory', 'social.contrib.old_age_capped', 'employee_rate'];
        [$draft->employerSource, $draft->employerKey, $draft->employerPath] = ['regulatory', 'social.contrib.old_age_capped', 'employer_rate'];
        $draft->body = '1';
        $draft->line = PayslipLine::SocialSecurityCapped->value;

        return $draft;
    }
}
