<?php

declare(strict_types=1);

namespace App\Tests\Controller\Payroll;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Enum\Dossier\DossierKind;
use App\Repository\Identity\SchoolYearRepository;
use App\Repository\Payroll\PayrollEstablishmentRepository;
use App\Repository\Payroll\PayrollItemRepository;
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
 * Paie et social › Paramètres de paie through HTTP (step 4.1): ines.morel's dossier, its date the
 * 15/03/2026, the millésime files installed. The standard plan offered on an empty plan, a rubrique
 * changed and refused, the establishment saved.
 */
final class PayrollScreensTest extends WebTestCase
{
    use BuildsSchool;
    use SavesDossiers;
    use SignsIn;

    public function testThePlanTheRubriqueAndTheEstablishment(): void
    {
        [$client, $dossier] = $this->school();

        $crawler = $client->request('GET', $this->url($dossier, '/payroll/items'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Le plan de paie est vide');
        $token = (string) $crawler->filter('form[action$="/payroll/items/install"] input[name="_token"]')->attr('value');
        $client->request('POST', $this->url($dossier, '/payroll/items/install'), ['_token' => $token]);
        self::assertResponseRedirects($this->url($dossier, '/payroll/items'));
        $client->followRedirect();
        self::assertSelectorTextContains('main', 'Vieillesse plafonnée');
        self::assertSelectorTextContains('main', '8,55');

        // A rate of the dossier in place of the law: refused above 100 %, then saved.
        $form = $client->request('GET', $this->url($dossier, '/payroll/items/2050'))->filter('#payroll-item-form')->form();
        $form['employerSource'] = 'fixed';
        $form['employerKey'] = '';
        $form['employerPath'] = '';
        $form['employerValue'] = '150';
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Un taux est compris entre 0 et 100 %.');
        $form['employerValue'] = '1,80';
        $client->submit($form);
        self::assertResponseRedirects($this->url($dossier, '/payroll/items/2050'));
        $item = $client->getContainer()->get(DossierContext::class)->within($dossier, static fn () => $client->getContainer()->get(PayrollItemRepository::class)->findOneByCode($dossier, '2050'));
        self::assertSame('1.8', $item?->getEmployerRate()->getPercent());

        $form = $client->request('GET', $this->url($dossier, '/payroll/establishment'))->filter('#payroll-establishment-form')->form();
        $form['headcount'] = '8,69';
        $form['workAccidentRate'] = '2,10';
        $form['monthlyHours'] = '151,67';
        $client->submit($form);
        self::assertResponseRedirects($this->url($dossier, '/payroll/establishment'));
        $establishment = $client->getContainer()->get(DossierContext::class)->within($dossier, static fn () => $client->getContainer()->get(PayrollEstablishmentRepository::class)->findOfDossier($dossier));
        self::assertSame(['8.69', '2.1', null], [$establishment?->getHeadcount(), $establishment?->getWorkAccidentRate(), $establishment?->getMobilityRate()]);

        $client->request('GET', $this->url($dossier, '/payroll/items'));
        self::assertSelectorTextContains('main', "Salaire brut 2,1\u{a0}%", 'The work accident rubrique reads the establishment.');
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
        $client->getContainer()->get(RegulatorySync::class)->run(new \DateTimeImmutable('2026-11-28 10:00'));
        $this->signOut($client);
        $this->submitLogin($client, 'ines.morel');
        $ines = $this->account($client, 'ines.morel');
        $year = $client->getContainer()->get(SchoolYearRepository::class)->findCurrent();
        \assert($ines instanceof User && $year instanceof SchoolYear);

        return [$client, $this->savedDossier(DossierKind::Student, $ines, $year)];
    }

    private function url(Dossier $dossier, string $path): string
    {
        return '/d/'.$dossier->getId().$path;
    }
}
