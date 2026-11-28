<?php

declare(strict_types=1);

namespace App\Tests\Controller\Payroll;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Entity\Payroll\PayrollEstablishment;
use App\Enum\Dossier\DossierKind;
use App\Repository\Identity\SchoolYearRepository;
use App\Service\Dossier\DossierContext;
use App\Service\Identity\SchoolClasses;
use App\Service\Payroll\Employee\EmployeeDraft;
use App\Service\Payroll\Employee\EmployeeSheet;
use App\Service\Payroll\Plan\PayrollPlanInstaller;
use App\Service\Regulatory\RegulatorySync;
use App\Tests\Support\BuildsSchool;
use App\Tests\Support\SavesDossiers;
use App\Tests\Support\SignsIn;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Paie et social › Bulletins through HTTP (step 4.4a): ines.morel's dossier on 15/03/2026, the standard
 * plan, Lucie Perrin, comptable at 2 730,00 since 01/01/2026. March lists her payslip « À faire »; a
 * card with an unreadable amount is refused whole; with a seniority bonus of 3 % the payslip is
 * calculated (2 730,00 + 81,90 = 2 811,90 of gross pay) and « Calculé, à valider », and the card keeps
 * what was typed.
 */
final class PayslipScreensTest extends WebTestCase
{
    use BuildsSchool;
    use SavesDossiers;
    use SignsIn;

    public function testThePayslipOfTheMonth(): void
    {
        [$client, $dossier] = $this->school();

        $client->request('GET', $this->url($dossier, '/payroll/payslips'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Bulletins de mars 2026');
        self::assertSelectorTextContains('main tbody', 'Perrin Lucie');
        self::assertSelectorTextContains('main tbody', 'À faire');

        $crawler = $client->request('GET', $this->url($dossier, '/payroll/payslips/S010'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Bulletin à calculer');
        $form = $crawler->filter('#payslip-elements')->form();
        $form['rate[1010]'] = '3';
        $form['amount[1020]'] = 'cent';
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Ce nombre n’est pas lisible.');

        $form = $client->getCrawler()->filter('#payslip-elements')->form();
        $form['amount[1020]'] = '';
        $client->submit($form);
        self::assertResponseRedirects($this->url($dossier, '/payroll/payslips/S010'));
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('body', 'Bulletin de Lucie Perrin calculé.');
        self::assertSelectorTextContains('main', 'Calculé, à valider');
        self::assertSame('3', $crawler->filter('#el-rate-1010')->attr('value'));
        $gross = $crawler->filter('tr.px-table__subtotal')->first()->text();
        self::assertStringContainsString('Salaire brut', $gross);
        self::assertSame('281190', preg_replace('/\D/', '', $gross), 'Base 2 730,00 + 3 % = 2 811,90.');
        self::assertStringContainsString('Taux saisi dans les éléments du mois', $crawler->filter('[data-payslip-target="line"] template')->eq(1)->html());

        $client->request('GET', $this->url($dossier, '/payroll/payslips/S999'));
        self::assertResponseStatusCodeSame(404);
    }

    /** @return array{KernelBrowser, Dossier} the client signed in as ines.morel, her dossier */
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
        $container = $client->getContainer();
        $container->get(RegulatorySync::class)->run(new \DateTimeImmutable('2026-11-28 10:00'));
        $container->get(DossierContext::class)->within($dossier, static function () use ($container, $dossier): void {
            $entityManager = $container->get(EntityManagerInterface::class);
            $entityManager->persist(new PayrollEstablishment($dossier, '8.69', '2.1', null, '151.67'));
            $entityManager->flush();
            $container->get(PayrollPlanInstaller::class)->installStandard($dossier, 'test');
            $entityManager->flush();
            $sheet = $container->get(EmployeeSheet::class);
            $draft = new EmployeeDraft();
            [$draft->registrationNumber, $draft->lastName, $draft->firstName, $draft->sex, $draft->birthDate] = ['S010', 'Perrin', 'Lucie', 'F', '1994-06-12'];
            [$draft->contractType, $draft->hiredOn] = ['permanent', '2021-09-01'];
            [$draft->jobTitle, $draft->classification, $draft->status, $draft->workingTime] = ['Assistante administrative', '', 'non_executive', 'full_time'];
            $draft->baseSalary = 210_000;
            $lucie = $sheet->create($dossier, $sheet->check($draft, $dossier) ?? throw new \LogicException(implode(' ', $draft->errors)), 'test');
            $entityManager->flush();
            $draft = EmployeeDraft::of($lucie, $lucie->termsAt(new \DateTimeImmutable('2021-09-01')));
            [$draft->termsFrom, $draft->jobTitle, $draft->baseSalary] = ['2026-01-01', 'Comptable', 273_000];
            $sheet->update($lucie, $sheet->check($draft, $dossier, $lucie) ?? throw new \LogicException(implode(' ', $draft->errors)), 'test');
            $entityManager->flush();
        });

        return [$client, $dossier];
    }

    private function url(Dossier $dossier, string $path): string
    {
        return '/d/'.$dossier->getId().$path;
    }
}
