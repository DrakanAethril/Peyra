<?php

declare(strict_types=1);

namespace App\Tests\Controller\Payroll;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Entity\Payroll\PayrollEstablishment;
use App\Enum\Dossier\DossierKind;
use App\Repository\Identity\SchoolYearRepository;
use App\Repository\Payroll\EmployeeRepository;
use App\Service\Dossier\DossierContext;
use App\Service\Identity\SchoolClasses;
use App\Service\Regulatory\RegulatorySync;
use App\Tests\Support\BuildsSchool;
use App\Tests\Support\SavesDossiers;
use App\Tests\Support\SignsIn;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Paie et social › Salariés through HTTP (step 4.2a): ines.morel's dossier, its date the
 * 15/03/2026. Julien Roche hired (refused first), raised from 01/04/2026 - a second row of the
 * history -, the raise taken back, then the employee deleted.
 */
final class EmployeeScreensTest extends WebTestCase
{
    use BuildsSchool;
    use SavesDossiers;
    use SignsIn;

    public function testHireRaiseAndDelete(): void
    {
        [$client, $dossier] = $this->school();

        $client->request('GET', $this->url($dossier, '/payroll/employees'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Aucun salarié dans ce dossier.');

        $form = $client->request('GET', $this->url($dossier, '/payroll/employees/new'))->filter('#employee-form')->form();
        self::assertSame('S001', $form['registrationNumber']->getValue());
        $form['registrationNumber'] = 'S005';
        $form['lastName'] = 'Roche';
        $form['firstName'] = 'Julien';
        $form['sex'] = 'M';
        $form['birthDate'] = '1990-09-05';
        $form['nir'] = '2 90 09 42 147 233 00';
        $form['hiredOn'] = '2015-03-02';
        $form['jobTitle'] = 'Menuisier d’atelier qualifié';
        $form['baseSalary'] = '225000';
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Le numéro commence par 1 pour un homme.');

        $form['nir'] = '1 90 09 42 147 233 00';
        $client->submit($form);
        self::assertResponseRedirects($this->url($dossier, '/payroll/employees/S005'));
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('main', 'Embauche');
        self::assertSame('1 90 09 42 147 233 00', $crawler->filter('#nir')->attr('value'));

        // The raise from 01/04/2026: a second row, still to come at the dossier's date.
        $form = $crawler->filter('#employee-form')->form();
        $form['termsFrom'] = '2026-04-01';
        $form['baseSalary'] = '238000';
        $client->submit($form);
        self::assertResponseRedirects($this->url($dossier, '/payroll/employees/S005?from=2026-04-01'));
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('main', "Salaire de base : 2\u{202F}250,00 → 2\u{202F}380,00");
        self::assertSelectorTextContains('main', 'À venir');

        $client->request('GET', $this->url($dossier, '/payroll/employees?state=present'));
        self::assertSelectorTextContains('main', 'Roche Julien');
        self::assertSelectorTextContains('main', "2\u{202F}250,00", 'The list reads the terms in force at the dossier’s date.');

        $token = (string) $crawler->filter('form[action$="/terms/2026-04-01/delete"] input[name="_token"]')->attr('value');
        $client->request('POST', $this->url($dossier, '/payroll/employees/S005/terms/2026-04-01/delete'), ['_token' => $token]);
        self::assertResponseRedirects($this->url($dossier, '/payroll/employees/S005'));
        $employee = $client->getContainer()->get(DossierContext::class)->within($dossier, static fn () => $client->getContainer()->get(EmployeeRepository::class)->findOneByNumber($dossier, 'S005'));
        self::assertCount(1, $employee?->getTerms() ?? []);

        $crawler = $client->request('GET', $this->url($dossier, '/payroll/employees/S005'));
        $token = (string) $crawler->filter('#employee-delete input[name="_token"]')->attr('value');
        $client->request('POST', $this->url($dossier, '/payroll/employees/S005/delete'), ['_token' => $token]);
        self::assertResponseRedirects($this->url($dossier, '/payroll/employees'));
        $client->request('GET', $this->url($dossier, '/payroll/employees/S005'));
        self::assertResponseStatusCodeSame(404);
    }

    /** @return array{KernelBrowser, Dossier} */
    private function school(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->submitLogin($client, 'prof.martin');
        $martin = $this->account($client, 'prof.martin');
        \assert($martin instanceof User);
        [, $cg1] = $this->buildYear($client, $martin, 2026, true);
        $client->getContainer()->get(SchoolClasses::class)->addTeacher($cg1, $martin, $martin, new \DateTimeImmutable('2026-11-27 10:00'));
        $client->getContainer()->get(EntityManagerInterface::class)->flush();
        $this->signOut($client);
        $this->submitLogin($client, 'ines.morel');
        $ines = $this->account($client, 'ines.morel');
        $year = $client->getContainer()->get(SchoolYearRepository::class)->findCurrent();
        \assert($ines instanceof User && $year instanceof SchoolYear);
        $dossier = $this->savedDossier(DossierKind::Student, $ines, $year);
        $client->getContainer()->get(RegulatorySync::class)->run(new \DateTimeImmutable('2026-11-28 10:00'));
        $client->getContainer()->get(EntityManagerInterface::class)->persist(new PayrollEstablishment($dossier, '8.69', '2.1', null, '151.67'));
        $client->getContainer()->get(EntityManagerInterface::class)->flush();

        return [$client, $dossier];
    }

    private function url(Dossier $dossier, string $path): string
    {
        return '/d/'.$dossier->getId().$path;
    }
}
