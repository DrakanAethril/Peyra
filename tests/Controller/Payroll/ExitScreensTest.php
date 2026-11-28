<?php

declare(strict_types=1);

namespace App\Tests\Controller\Payroll;

use App\Entity\Audit\AuditEvent;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Entity\Payroll\PayrollEstablishment;
use App\Enum\Audit\AuditAction;
use App\Enum\Dossier\DossierKind;
use App\Enum\Payroll\EmployeeState;
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
 * Paie et social › Salariés › Fin de contrat through HTTP (step 4.2c): ines.morel's dossier on
 * 15/03/2026, Lucie Perrin, assistante then comptable on a CDI since 01/09/2021. A resignation typed
 * without its letter is refused; with it (02/02, last day 06/03) it is recorded, the work
 * certificate printed, Lucie « Sortie » in the list and the register; a fiche change starting after
 * the exit is refused; the exit cancelled.
 */
final class ExitScreensTest extends WebTestCase
{
    use BuildsSchool;
    use SavesDossiers;
    use SignsIn;

    public function testAnExitItsCertificateAndItsCancellation(): void
    {
        [$client, $dossier] = $this->school();

        $crawler = $client->request('GET', $this->url($dossier, '/payroll/employees/S010/exit'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Contrat à durée indéterminée (CDI) depuis le 01/09/2021.');
        self::assertSelectorTextContains('main', 'Aucun document pour l’instant.');
        self::assertSame(['', 'resignation', 'personal_dismissal', 'economic_dismissal', 'conventional_termination', 'retirement', 'trial_period'], $crawler->filter('#reason option')->each(static fn ($o): string => (string) $o->attr('value')), 'The reasons a CDI allows.');
        $client->request('GET', $this->url($dossier, '/payroll/employees/S010/work-certificate.pdf'));
        self::assertResponseStatusCodeSame(404, 'No certificate before the exit.');

        $crawler = $client->request('GET', $this->url($dossier, '/payroll/employees/S010/exit'));
        $form = $crawler->filter('#exit-form')->form();
        $form['reason'] = 'resignation';
        $form['exitOn'] = '2026-03-06';
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Indiquez le jour où la sortie a été notifiée.');

        $form = $client->getCrawler()->filter('#exit-form')->form();
        $form['notifiedOn'] = '2026-02-02';
        $client->submit($form);
        self::assertResponseRedirects($this->url($dossier, '/payroll/employees/S010/exit'));
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Sortie enregistrée.');
        self::assertSelectorTextContains('main', 'Sorti');
        self::assertSelectorTextContains('main', 'le dernier jour du contrat, le 06/03/2026');

        $client->request('GET', $this->url($dossier, '/payroll/employees/S010/work-certificate.pdf'));
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringStartsWith('%PDF-', (string) $client->getInternalResponse()->getContent());

        $client->request('GET', $this->url($dossier, '/payroll/employees?state=departed'));
        self::assertSelectorTextContains('main', 'Perrin Lucie');
        $client->request('GET', $this->url($dossier, '/payroll/employees/register'));
        self::assertSelectorTextContains('main', '01/09/2021 06/03/2026');

        // The fiche keeps the exit standing: no terms after the last day.
        $container = $client->getContainer();
        $container->get(DossierContext::class)->within($dossier, static function () use ($container, $dossier): void {
            $lucie = $container->get(EmployeeRepository::class)->findOneByNumber($dossier, 'S010') ?? throw new \LogicException('S010');
            self::assertSame(EmployeeState::Departed, $lucie->stateAt($dossier->getDossierDate()));
            self::assertSame(EmployeeState::Present, $lucie->stateAt(new \DateTimeImmutable('2026-03-06')), 'Present on the last day.');
            $draft = EmployeeDraft::of($lucie, $lucie->termsAt($dossier->getDossierDate()));
            $draft->termsFrom = '2026-04-01';
            $draft->baseSalary = 280_000;
            self::assertNull($container->get(EmployeeSheet::class)->check($draft, $dossier, $lucie));
            self::assertSame('Le contrat prend fin le 06/03/2026 : aucune condition ne commence après.', $draft->errors['termsFrom']);
            $draft = EmployeeDraft::of($lucie, $lucie->termsAt($dossier->getDossierDate()));
            [$draft->contractType, $draft->contractEndsOn, $draft->fixedTermReason] = ['fixed_term', '2026-12-31', 'Remplacement'];
            self::assertNull($container->get(EmployeeSheet::class)->check($draft, $dossier, $lucie));
            self::assertSame('La sortie enregistrée ne tiendrait plus : « Démission » ne met pas fin à un CDD. Modifiez ou annulez la sortie d’abord (onglet Fin de contrat).', $draft->errors['contractType']);
        });

        $crawler = $client->request('GET', $this->url($dossier, '/payroll/employees/S010/exit'));
        $token = (string) $crawler->filter('#exit-cancel input[name="_token"]')->attr('value');
        $client->request('POST', $this->url($dossier, '/payroll/employees/S010/exit/cancel'), ['_token' => $token]);
        self::assertResponseRedirects($this->url($dossier, '/payroll/employees/S010/exit'));
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Sortie annulée.');
        self::assertSelectorTextContains('main', 'Aucun document pour l’instant.');

        $events = $container->get(EntityManagerInterface::class)->getRepository(AuditEvent::class);
        self::assertSame(1, $events->count(['action' => AuditAction::EmployeeExitRecorded]));
        self::assertSame(1, $events->count(['action' => AuditAction::EmployeeExitCancelled]));
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
