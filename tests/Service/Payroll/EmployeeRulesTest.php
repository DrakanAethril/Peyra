<?php

declare(strict_types=1);

namespace App\Tests\Service\Payroll;

use App\Enum\Payroll\ContractType;
use App\Enum\Payroll\Sex;
use App\Enum\Payroll\WorkingTime;
use App\Service\Payroll\Employee\EmployeeDraft;
use App\Service\Payroll\Employee\EmployeeFields;
use App\Service\Payroll\Employee\EmployeeRules;
use App\Service\Payroll\Employee\Nir;
use PHPUnit\Framework\TestCase;

/**
 * What makes an employee (step 4.2a), on the demonstration's own people: Thomas Lefèvre (CDI, full
 * time), Sandrine Gauthier (part time, 104 h), Yanis Chabert (CDD), Hugo Marchand (apprentice).
 */
final class EmployeeRulesTest extends TestCase
{
    private const string COLLECTIVE = '151.67';

    public function testAFullTimeContractWorksTheCollectiveTime(): void
    {
        $draft = self::thomas();
        $draft->monthlyHours = '120';
        $draft->registrationNumber = ' s003 ';
        $draft->nir = '1 94 04 42 147 118 00';
        $fields = self::check($draft);

        self::assertNotNull($fields, implode(' ', $draft->errors));
        self::assertSame('S003', $fields->registrationNumber);
        self::assertSame('194044214711800', $fields->nir);
        self::assertSame(WorkingTime::FullTime, $fields->workingTime);
        self::assertSame('151.67', $fields->monthlyHours, 'Full time: the establishment’s time, whatever was typed.');
        self::assertSame(240_000, $fields->baseSalary->cents);
        self::assertSame('3.5', $fields->withholdingRate);
        self::assertFalse($fields->executive);
        self::assertSame(Sex::Male, $fields->sex);
        self::assertSame('2022-12-01', $fields->termsFrom->format('Y-m-d'));
        self::assertNull($fields->contractEndsOn);
    }

    public function testAPartTimeContractWorksFewerHours(): void
    {
        $draft = self::thomas();
        $draft->workingTime = 'part_time';
        $draft->monthlyHours = '104,00';
        $draft->baseSalary = 147_680;
        $fields = self::check($draft);
        self::assertNotNull($fields);
        self::assertSame('104', $fields->monthlyHours);

        $draft->monthlyHours = '151,67';
        self::assertNull(self::check($draft));
        self::assertSame('Un temps partiel travaille moins que l’horaire collectif (151,67 h par mois).', $draft->error('monthlyHours'));
        $draft->monthlyHours = '';
        self::assertNull(self::check($draft));
        self::assertSame('Un temps partiel indique son horaire mensuel.', $draft->error('monthlyHours'));
        $draft->monthlyHours = '104,005';
        self::assertNull(self::check($draft));
        self::assertSame('2 décimales au plus.', $draft->error('monthlyHours'));
    }

    public function testNoWorkingTimeWithoutAnEstablishment(): void
    {
        $draft = self::thomas();
        self::assertNull(EmployeeRules::check($draft, null, static fn (): ?string => null));
        self::assertSame('Renseignez d’abord l’horaire collectif de l’établissement (Paramètres de paie › Établissement).', $draft->error('workingTime'));
    }

    public function testAFixedTermContractNamesItsEndAndItsGround(): void
    {
        $draft = self::thomas();
        $draft->contractType = 'fixed_term';
        $draft->hiredOn = '2026-04-01';
        $draft->termsFrom = '2026-04-01';
        self::assertNull(self::check($draft));
        self::assertSame('Un CDD indique sa date de fin.', $draft->error('contractEndsOn'));
        self::assertStringStartsWith('Un CDD indique son motif de recours', (string) $draft->error('fixedTermReason'));

        $draft->contractEndsOn = '2026-04-01';
        $draft->fixedTermReason = 'Accroissement temporaire d’activité';
        self::assertNull(self::check($draft));
        self::assertSame('La fin du contrat suit la date d’entrée.', $draft->error('contractEndsOn'));

        $draft->contractEndsOn = '2026-09-30';
        $draft->diploma = 'CAP';
        $fields = self::check($draft);
        self::assertNotNull($fields);
        self::assertSame(ContractType::FixedTerm, $fields->contractType);
        self::assertSame('2026-09-30', $fields->contractEndsOn?->format('Y-m-d'));
        self::assertNull($fields->diploma, 'What a CDD does not name is dropped.');

        $draft->contractType = 'permanent';
        $fields = self::check($draft);
        self::assertNotNull($fields);
        self::assertNull($fields->contractEndsOn);
        self::assertNull($fields->fixedTermReason);
    }

    public function testAnApprenticeIsNotCadreAndPreparesADiploma(): void
    {
        $draft = self::thomas();
        [$draft->contractType, $draft->hiredOn, $draft->termsFrom, $draft->contractEndsOn, $draft->status] = ['apprenticeship', '2025-09-01', '2025-09-01', '2027-08-31', 'executive'];
        self::assertNull(self::check($draft));
        self::assertSame('Un apprenti est non-cadre.', $draft->error('status'));
        self::assertSame('Indiquez le diplôme préparé.', $draft->error('diploma'));

        $draft->status = 'non_executive';
        $draft->diploma = 'CAP menuisier fabricant';
        $fields = self::check($draft);
        self::assertNotNull($fields);
        self::assertSame('CAP menuisier fabricant', $fields->diploma);
    }

    public function testDatesFollowEachOther(): void
    {
        $draft = self::thomas();
        $draft->birthDate = '2022-12-01';
        $draft->termsFrom = '2022-11-30';
        self::assertNull(self::check($draft));
        self::assertSame('La date de naissance précède la date d’entrée.', $draft->error('birthDate'));
        self::assertSame('Les conditions du contrat s’appliquent à compter de la date d’entrée au plus tôt.', $draft->error('termsFrom'));

        $draft = self::thomas();
        $draft->hiredOn = '2026-02-30';
        self::assertNull(self::check($draft));
        self::assertSame('Cette date n’existe pas.', $draft->error('hiredOn'));
    }

    public function testTheMatriculeIsTheDossiersOwn(): void
    {
        $draft = self::thomas();
        self::assertNull(EmployeeRules::check($draft, self::COLLECTIVE, static fn (string $number): ?string => 'S003' === $number ? 'Paul Vasseur' : null));
        self::assertSame('Le matricule S003 est déjà celui de Paul Vasseur.', $draft->error('registrationNumber'));

        $draft->registrationNumber = 'S-003';
        self::assertNull(self::check($draft));
        self::assertSame('Un matricule compte de 1 à 10 lettres ou chiffres, sans accent (S001).', $draft->error('registrationNumber'));
    }

    public function testTheWithholdingRate(): void
    {
        $draft = self::thomas();
        $draft->withholdingRate = '';
        self::assertNull(self::check($draft)?->withholdingRate, 'Nothing sent: the neutral rate.');
        $draft->withholdingRate = '0';
        self::assertSame('0', self::check($draft)?->withholdingRate);
        foreach (['101', '-1', '3,125', 'trois'] as $typed) {
            $draft->withholdingRate = $typed;
            self::assertNull(self::check($draft), $typed);
            self::assertNotNull($draft->error('withholdingRate'), $typed);
        }
    }

    public function testTheBaseSalaryIsAboveZero(): void
    {
        $draft = self::thomas();
        $draft->baseSalary = null;
        self::assertNull(self::check($draft));
        self::assertSame('Indiquez le salaire de base mensuel.', $draft->error('baseSalary'));
        $draft->baseSalary = 0;
        self::assertNull(self::check($draft));
        self::assertSame('Un salaire de base est supérieur à zéro.', $draft->error('baseSalary'));
    }

    public function testTheNirAgreesWithTheCivilStatusAndItsKeyIsNotChecked(): void
    {
        $born = new \DateTimeImmutable('1994-04-17');
        self::assertNull(Nir::fault('194044214711800', Sex::Male, $born), 'Key « 00 », invalid by construction, accepted.');
        self::assertNull(Nir::fault('194042A14711800', Sex::Male, $born), 'Corsica, 2A.');
        self::assertNull(Nir::fault('194042B14711800', Sex::Male, $born), 'Corsica, 2B.');
        self::assertSame('Le numéro commence par 2 pour une femme.', Nir::fault('194044214711800', Sex::Female, $born));
        self::assertSame('Les chiffres 2 et 3 du numéro sont l’année de naissance : 94.', Nir::fault('195044214711800', Sex::Male, $born));
        self::assertSame('Les chiffres 4 et 5 du numéro sont le mois de naissance : 04.', Nir::fault('194054214711800', Sex::Male, $born));
        self::assertNull(Nir::fault('194994214711800', Sex::Male, $born), 'Month unknown (99): accepted.');
        self::assertNotNull(Nir::fault('19404421471180', Sex::Male, $born), 'Fourteen characters.');
        self::assertNotNull(Nir::fault('394044214711800', null, null), 'Neither 1 nor 2.');
        self::assertSame('194044214711800', Nir::normalize('1 94 04 42 147 118 00'));
        self::assertSame('1 94 04 42 147 118 00', Nir::format('194044214711800'));

        $draft = self::thomas();
        $draft->nir = '2 94 04 42 147 118 00';
        self::assertNull(self::check($draft));
        self::assertSame('Le numéro commence par 1 pour un homme.', $draft->error('nir'));
    }

    public function testTheHourOfHireAndTheTrialPeriod(): void
    {
        $draft = self::thomas();
        foreach (['8h' => '08:00', '8h30' => '08:30', '7:30' => '07:30', '07:30' => '07:30', ' 13 h 05 ' => '13:05'] as $typed => $hour) {
            $draft->hireTime = (string) $typed;
            self::assertSame($hour, self::check($draft)?->hireTime, (string) $typed);
        }
        foreach (['24:00', '8h75', 'huit heures'] as $typed) {
            $draft->hireTime = $typed;
            self::assertNull(self::check($draft), $typed);
            self::assertSame('Une heure s’écrit 08:30 ou 8h30.', $draft->error('hireTime'));
        }
        $draft->hireTime = '';
        $draft->trialPeriod = '  2   mois ';
        $fields = self::check($draft);
        self::assertNull($fields?->hireTime);
        self::assertSame('2 mois', $fields?->trialPeriod);
    }

    public function testTheDpaesFieldsAreRequiredWhenTheLevelAsks(): void
    {
        $draft = self::thomas();
        self::assertNotNull(EmployeeRules::check($draft, self::COLLECTIVE, static fn (): ?string => null, false), 'Without the level: birth place and hour may wait.');
        self::assertNull(EmployeeRules::check($draft, self::COLLECTIVE, static fn (): ?string => null, true));
        self::assertSame('La DPAE indique le lieu de naissance.', $draft->error('birthPlace'));
        self::assertSame('La DPAE indique l’heure d’embauche.', $draft->error('hireTime'));

        [$draft->birthPlace, $draft->hireTime] = ['Montbrison (42)', '8h'];
        self::assertNotNull(EmployeeRules::check($draft, self::COLLECTIVE, static fn (): ?string => null, true));
    }

    private static function check(EmployeeDraft $draft): ?EmployeeFields
    {
        return EmployeeRules::check($draft, self::COLLECTIVE, static fn (): ?string => null);
    }

    private static function thomas(): EmployeeDraft
    {
        $draft = new EmployeeDraft();
        [$draft->registrationNumber, $draft->lastName, $draft->firstName, $draft->sex, $draft->birthDate] = ['S003', 'Lefèvre', 'Thomas', 'M', '1994-04-17'];
        [$draft->contractType, $draft->hiredOn, $draft->termsFrom] = ['permanent', '2022-12-01', '2022-12-01'];
        [$draft->jobTitle, $draft->classification, $draft->status, $draft->workingTime] = ['Menuisier poseur', 'Ouvrier qualifié, niveau interne O3', 'non_executive', 'full_time'];
        $draft->baseSalary = 240_000;
        $draft->withholdingRate = '3,5';

        return $draft;
    }
}
