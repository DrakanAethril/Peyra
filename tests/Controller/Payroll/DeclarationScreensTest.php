<?php

declare(strict_types=1);

namespace App\Tests\Controller\Payroll;

use App\Entity\Accounting\Entry;
use App\Entity\Accounting\Journal;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Entity\Payroll\PayrollEstablishment;
use App\Enum\Accounting\AssistanceLevel;
use App\Enum\Accounting\JournalType;
use App\Enum\Dossier\DossierKind;
use App\Repository\Accounting\AccountRepository;
use App\Repository\Accounting\EntryRepository;
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
 * Paie et social › Déclarations and the variables' export through HTTP (step 4.6): ines.morel's dossier
 * on 15/03/2026 (8,69 employees: the DSN is due on the 15th), Lucie Perrin at 2 730,00 with no rate sent
 * (neutral rate). Her March payslip validated, the DSN's preparatory state reads it - each amount leading
 * to her payslip, its checks passed -, prints, and the échéancier lists the Urssaf's, the pension's and the
 * tax's payments for 15/04/2026, to pay; the tax paid back from BQ (PAS-2026-03) stands as paid. The
 * variables of the month leave as a spreadsheet.
 */
final class DeclarationScreensTest extends WebTestCase
{
    use BuildsSchool;
    use SavesDossiers;
    use SignsIn;

    public function testTheDsnTheScheduleAndTheExport(): void
    {
        [$client, $dossier] = $this->school();
        $client->request('GET', $this->url($dossier, '/payroll/declarations'));
        self::assertSelectorTextContains('main', 'Aucun bulletin validé dans ce dossier.');
        $client->request('GET', $this->url($dossier, '/payroll/declarations/dsn.pdf'));
        self::assertResponseStatusCodeSame(404);

        $payslip = $this->url($dossier, '/payroll/payslips/S010');
        $crawler = $client->request('GET', $payslip);
        $client->submit($crawler->filter('#payslip-elements')->form());
        $crawler = $client->followRedirect();
        $client->request('POST', $payslip.'/validate', ['_token' => $crawler->filter('form[action$="/validate"] input[name="_token"]')->attr('value')]);
        self::assertResponseRedirects();

        $crawler = $client->request('GET', $this->url($dossier, '/payroll/declarations'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'DSN de mars 2026');
        self::assertSelectorTextContains('#dsn-individuals', 'Perrin Lucie');
        self::assertSelectorTextContains('#dsn-establishment', '15/04/2026');
        self::assertCount(3, $crawler->filter('.px-checks__item.is-passed'), 'Totals, one payslip per employee, the NIR.');
        $links = $crawler->filter('#dsn-pay tbody a')->each(static fn ($a): string => (string) $a->attr('href'));
        self::assertContains($this->url($dossier, '/payroll/employees/S010/payslips/2026-03.pdf'), $links, 'An amount opens its payslip.');
        self::assertSelectorTextContains('#dsn-payments', 'Prélèvement à la source de mars 2026');
        $client->request('GET', $this->url($dossier, '/payroll/declarations/dsn.pdf?month=2026-03'));
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');

        $crawler = $client->request('GET', $this->url($dossier, '/payroll/declarations/schedule'));
        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('main tbody tr')->each(static fn ($tr): string => (string) preg_replace('/\s+/u', ' ', trim($tr->text())));
        self::assertCount(3, $rows, 'Urssaf, pension, tax.');
        foreach ($rows as $row) {
            self::assertStringStartsWith('15/04/2026', $row);
            self::assertStringContainsString('À payer', $row);
        }

        // The tax paid back from BQ, from Écritures de paie.
        $crawler = $client->request('GET', $this->url($dossier, '/payroll/entries?month=2026-03'));
        $form = $crawler->filter('form[action$="/entries/pay"]')->reduce(static fn ($f): bool => 'withholding' === $f->filter('input[name="payment"]')->attr('value'));
        self::assertCount(1, $form);
        $client->request('POST', $this->url($dossier, '/payroll/entries/pay'), ['month' => '2026-03', 'payment' => 'withholding', 'bank' => 'BQ', '_token' => $form->filter('button[name="_token"]')->attr('value')]);
        $client->followRedirect();
        $paid = $this->only($client, $dossier, 'PAS-2026-03');
        self::assertSame(['BQ', '442100', '512000'], [$paid->getJournal()->getCode(), $paid->getLines()[0]->getAccount()->getNumber(), $paid->getLines()[1]->getAccount()->getNumber()]);
        $crawler = $client->request('GET', $this->url($dossier, '/payroll/declarations/schedule'));
        $tax = $crawler->filter('main tbody tr')->reduce(static fn ($tr): bool => str_contains($tr->text(), 'PAS-2026-03'));
        self::assertStringContainsString('Payée', $tax->text());
        self::assertStringContainsString(number_format($paid->getLines()[0]->getDebit()->cents / 100, 2, ',', "\u{202f}"), $tax->text());

        $client->request('GET', $this->url($dossier, '/payroll/payslips/export.xlsx?month=2026-03'));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        self::assertStringContainsString('variables-de-paie-2026-03', (string) $client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringStartsWith('PK', (string) $client->getResponse()->getContent());
    }

    private function only(KernelBrowser $client, Dossier $dossier, string $document): Entry
    {
        $entries = $this->entries($client, $dossier, $document);
        self::assertCount(1, $entries, $document);

        return $entries[0];
    }

    /** @return list<Entry> */
    private function entries(KernelBrowser $client, Dossier $dossier, string $document): array
    {
        $container = $client->getContainer();
        $container->get(EntityManagerInterface::class)->clear();

        return $container->get(DossierContext::class)->within($dossier, static fn (): array => $container->get(EntryRepository::class)->findByDocumentReference($dossier, $document));
    }

    /** @return array{KernelBrowser, Dossier} the client signed in as ines.morel, her dossier ready for the payroll's entries */
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
        $ines->setAssistanceLevel(AssistanceLevel::Guided);
        $dossier = $this->savedDossier(DossierKind::Student, $ines, $year);
        $container = $client->getContainer();
        $container->get(RegulatorySync::class)->run(new \DateTimeImmutable('2026-11-28 10:00'));
        $container->get(DossierContext::class)->within($dossier, function () use ($container, $dossier): void {
            $this->installPlan($dossier);
            $entityManager = $container->get(EntityManagerInterface::class);
            $accounts = $container->get(AccountRepository::class);
            $entityManager->persist(new Journal($dossier, 'OD', 'Opérations diverses', JournalType::General, null));
            $entityManager->persist(new Journal($dossier, 'BQ', 'Banque', JournalType::Bank, $accounts->findOneByNumber($dossier, '512000')));
            $entityManager->persist(new PayrollEstablishment($dossier, '8.69', '2.1', null, '151.67'));
            $entityManager->flush();
            $container->get(PayrollPlanInstaller::class)->installStandard($dossier, 'test');
            $entityManager->flush();
            $sheet = $container->get(EmployeeSheet::class);
            $draft = new EmployeeDraft();
            [$draft->registrationNumber, $draft->lastName, $draft->firstName, $draft->sex, $draft->birthDate] = ['S010', 'Perrin', 'Lucie', 'F', '1994-06-12'];
            $draft->nir = '294067511200100';
            [$draft->contractType, $draft->hiredOn] = ['permanent', '2021-09-01'];
            [$draft->jobTitle, $draft->classification, $draft->status, $draft->workingTime] = ['Comptable', '', 'non_executive', 'full_time'];
            $draft->baseSalary = 273_000;
            $sheet->create($dossier, $sheet->check($draft, $dossier) ?? throw new \LogicException(implode(' ', $draft->errors)), 'test');
            $entityManager->flush();
        });

        return [$client, $dossier];
    }

    private function url(Dossier $dossier, string $path): string
    {
        return '/d/'.$dossier->getId().$path;
    }
}
