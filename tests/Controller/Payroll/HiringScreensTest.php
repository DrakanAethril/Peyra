<?php

declare(strict_types=1);

namespace App\Tests\Controller\Payroll;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Entity\Payroll\PayrollBody;
use App\Entity\Payroll\PayrollEstablishment;
use App\Enum\Dossier\DossierKind;
use App\Enum\Payroll\PayrollBodyKind;
use App\Repository\Identity\SchoolYearRepository;
use App\Repository\Payroll\EmployeeRepository;
use App\Service\Dossier\DossierContext;
use App\Service\Identity\SchoolClasses;
use App\Service\Payroll\Employee\EmployeeDraft;
use App\Service\Payroll\Employee\EmployeeSheet;
use App\Service\Regulatory\RegulatorySync;
use App\Tests\Support\BuildsSchool;
use App\Tests\Support\SavesDossiers;
use App\Tests\Support\SignsIn;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Paie et social › Salariés › Formalités and the simulated social portal through HTTP (step 4.2b):
 * ines.morel's dossier on 15/03/2026, Yanis Chabert hired on 20/03/2026 without an hour of hire. His
 * DPAE is rejected (no hour), the fiche completed, then accepted; the visit and the pension fund's
 * affiliation recorded; the contract, the acknowledgement and the register printed.
 */
final class HiringScreensTest extends WebTestCase
{
    use BuildsSchool;
    use SavesDossiers;
    use SignsIn;

    public function testTheDpaeTheFormalitiesAndTheDocuments(): void
    {
        [$client, $dossier, $pension] = $this->school();

        $client->request('GET', $this->url($dossier, '/payroll/employees'));
        self::assertSelectorTextContains('main', '4 à faire', 'The DPAE, the visit, two affiliations (Urssaf, pension).');

        $crawler = $client->request('GET', $this->url($dossier, '/payroll/employees/S011/formalities'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'au plus tôt le 12/03/2026');
        self::assertSelectorTextContains('main', 'au plus tard le 20/06/2026');

        $crawler = $client->request('GET', $this->url($dossier, '/payroll/social-portal/dpae/S011'));
        self::assertSelectorTextContains('main', 'Simulation : rien n’est transmis');
        $token = (string) $crawler->filter('form.px-confirmbar input[name="_token"]')->attr('value');
        $client->request('POST', $this->url($dossier, '/payroll/social-portal/dpae/S011'), ['_token' => $token]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'DPAE-E03 Heure d’embauche absente.');
        self::assertSelectorTextNotContains('main', 'c’est l’heure à laquelle', 'Explained at level G only.');

        $this->completeTheFiche($client, $dossier);
        $client->request('POST', $this->url($dossier, '/payroll/social-portal/dpae/S011'), ['_token' => $token]);
        self::assertResponseRedirects($this->url($dossier, '/payroll/social-portal/dpae/S011/receipt'));
        $client->followRedirect();
        self::assertSelectorTextContains('main', 'DPAE reçue le 15/03/2026 et enregistrée sous le n° DPAE-2026-000001.');
        self::assertSelectorTextContains('main', '20/03/2026 à 08:00');
        $client->request('GET', $this->url($dossier, '/payroll/social-portal/dpae/S011'));
        self::assertResponseRedirects($this->url($dossier, '/payroll/social-portal/dpae/S011/receipt'), null, 'One DPAE per employee.');

        $crawler = $client->request('GET', $this->url($dossier, '/payroll/employees/S011/formalities'));
        self::assertSelectorTextContains('main', 'accusé n° DPAE-2026-000001');
        $form = $crawler->filter('#formalities-form')->form();
        $form['visitOn'] = '2026-04-02';
        $form['affiliation['.$pension.']'] = '2026-03-20';
        $client->submit($form);
        self::assertResponseRedirects($this->url($dossier, '/payroll/employees/S011/formalities'));
        $client->followRedirect();
        self::assertSelectorTextContains('main', 'Caisse de retraite (fictive) Retraite complémentaire R-1 Affilié');
        $client->request('GET', $this->url($dossier, '/payroll/employees'));
        self::assertSelectorTextContains('main', 'À jour');

        foreach (['/payroll/employees/S011/contract.pdf', '/payroll/social-portal/dpae/S011/receipt.pdf', '/payroll/employees/register.pdf'] as $pdf) {
            $client->request('GET', $this->url($dossier, $pdf));
            self::assertResponseHeaderSame('Content-Type', 'application/pdf', $pdf);
            self::assertStringStartsWith('%PDF-', (string) $client->getInternalResponse()->getContent());
        }
        $client->request('GET', $this->url($dossier, '/payroll/employees/register'));
        self::assertSelectorTextContains('main', 'Chabert Yanis');
        self::assertSelectorTextContains('main', 'Ouvrier qualifié, niveau interne O3 (non-cadre)');
    }

    private function completeTheFiche(KernelBrowser $client, Dossier $dossier): void
    {
        $container = $client->getContainer();
        $container->get(DossierContext::class)->within($dossier, static function () use ($container, $dossier): void {
            $employee = $container->get(EmployeeRepository::class)->findOneByNumber($dossier, 'S011') ?? throw new \LogicException('S011');
            $draft = EmployeeDraft::of($employee, $employee->termsAt($dossier->getDossierDate()));
            $draft->hireTime = '8h';
            $sheet = $container->get(EmployeeSheet::class);
            $sheet->update($employee, $sheet->check($draft, $dossier, $employee) ?? throw new \LogicException(implode(' ', $draft->errors)), 'test');
            $container->get(EntityManagerInterface::class)->flush();
        });
    }

    /** @return array{KernelBrowser, Dossier, int} the client signed in as ines.morel, her dossier, the pension fund's id */
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
        $pension = $container->get(DossierContext::class)->within($dossier, static function () use ($container, $dossier): int {
            $entityManager = $container->get(EntityManagerInterface::class);
            $entityManager->persist(new PayrollEstablishment($dossier, '8.69', '2.1', null, '151.67'));
            $entityManager->persist(new PayrollBody($dossier, PayrollBodyKind::Urssaf, 'Urssaf (simulée)', 'U-1'));
            $entityManager->persist($pension = new PayrollBody($dossier, PayrollBodyKind::Retirement, 'Caisse de retraite (fictive)', 'R-1'));
            $entityManager->flush();
            $draft = new EmployeeDraft();
            [$draft->registrationNumber, $draft->lastName, $draft->firstName, $draft->sex, $draft->birthDate, $draft->birthPlace] = ['S011', 'Chabert', 'Yanis', 'M', '2001-05-09', 'Feurs (42)'];
            [$draft->contractType, $draft->hiredOn, $draft->contractEndsOn, $draft->fixedTermReason, $draft->trialPeriod] = ['fixed_term', '2026-03-20', '2026-09-30', 'Accroissement temporaire d’activité', '14 jours'];
            [$draft->jobTitle, $draft->classification, $draft->status, $draft->workingTime] = ['Menuisier poseur', 'Ouvrier qualifié, niveau interne O3', 'non_executive', 'full_time'];
            $draft->baseSalary = 195_000;
            $sheet = $container->get(EmployeeSheet::class);
            $sheet->create($dossier, $sheet->check($draft, $dossier) ?? throw new \LogicException(implode(' ', $draft->errors)), 'test');
            $entityManager->flush();

            return (int) $pension->getId();
        });

        return [$client, $dossier, $pension];
    }

    private function url(Dossier $dossier, string $path): string
    {
        return '/d/'.$dossier->getId().$path;
    }
}
