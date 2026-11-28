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
 * Paie et social › Éditions and Écritures de paie through HTTP (step 4.5): ines.morel's dossier on
 * 15/03/2026 (PCG 2026, journals OD and BQ on 512000, the standard plan), Lucie Perrin at 2 730,00.
 * Her March payslip validated, the four states read it - each amount leading to it -, the entry waits
 * for the month to close, then (« Proposer ») goes to the brouillard balanced, its net on 421000 the
 * payslip's; the net salaries are paid from BQ; the month reopened, the payslip cancelled takes its
 * entry back out of the brouillard, the payment stays.
 */
final class PayrollPostingScreensTest extends WebTestCase
{
    use BuildsSchool;
    use SavesDossiers;
    use SignsIn;

    public function testTheStatesTheEntryAndItsWithdrawal(): void
    {
        [$client, $dossier] = $this->school();
        $payslip = $this->url($dossier, '/payroll/payslips/S010');
        $crawler = $client->request('GET', $payslip);
        $client->submit($crawler->filter('#payslip-elements')->form());
        $crawler = $client->followRedirect();
        $client->request('POST', $payslip.'/validate', ['_token' => $crawler->filter('form[action$="/validate"] input[name="_token"]')->attr('value')]);
        self::assertResponseRedirects();

        $client->request('GET', $this->url($dossier, '/payroll/editions'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Journal de paie de mars 2026');
        self::assertSelectorTextContains('main', 'Salaire de base');
        $crawler = $client->request('GET', $this->url($dossier, '/payroll/editions/book?month=2026-03'));
        self::assertSelectorTextContains('main tbody', 'Perrin Lucie');
        $net = trim($crawler->filter('main tbody tr')->first()->filter('td')->eq(7)->text());
        $client->request('GET', $this->url($dossier, '/payroll/editions/bodies'));
        self::assertSelectorTextContains('main', 'Urssaf');
        self::assertSelectorTextContains('main', 'part retraite complémentaire');
        $client->request('GET', $this->url($dossier, '/payroll/editions/charges'));
        self::assertSelectorTextContains('main', 'Sécurité sociale plafonnée');
        $client->request('GET', $this->url($dossier, '/payroll/editions/detail?month=2026-03&item=1000'));
        self::assertSelectorTextContains('main tbody', 'S010');
        $client->request('GET', $this->url($dossier, '/payroll/editions/detail?month=2026-03'));
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', $this->url($dossier, '/payroll/editions/journal.pdf?month=2026-03'));
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        $client->request('GET', $this->url($dossier, '/payroll/editions/provision'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main tbody', 'Perrin Lucie');

        // March is open: its entry waits.
        $crawler = $client->request('GET', $this->url($dossier, '/payroll/entries'));
        self::assertSelectorTextContains('main', 'une fois la paie de mars 2026 close');
        self::assertCount(0, $crawler->filter('form[action$="/entries/post"]'));

        // Closed on the 15th, the entry of the 31st is said to come after the dossier's date; the dossier moves to the 31st.
        $crawler = $client->request('GET', $this->url($dossier, '/payroll/payslips'));
        $client->request('POST', $this->url($dossier, '/payroll/payslips/close'), ['_token' => $crawler->filter('form[action$="/close"] input[name="_token"]')->attr('value')]);
        $crawler = $client->request('GET', $this->url($dossier, '/payroll/entries?month=2026-03'));
        self::assertSelectorTextContains('main', 'Cette date est postérieure à la date du dossier');
        self::assertCount(0, $crawler->filter('form[action$="/entries/post"]'));
        $this->moveTo($client, $dossier, '2026-03-31');

        $crawler = $client->request('GET', $this->url($dossier, '/payroll/entries?month=2026-03'));
        self::assertSelectorTextContains('main', 'PAIE-2026-03');
        $client->request('POST', $this->url($dossier, '/payroll/entries/post'), ['month' => '2026-03', '_token' => $crawler->filter('form[action$="/entries/post"] button[name="_token"]')->attr('value')]);
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('body', 'L’écriture est au brouillard');
        $entry = $this->only($client, $dossier, 'PAIE-2026-03');
        self::assertSame('OD', $entry->getJournal()->getCode());
        self::assertFalse($entry->isValidated());
        $debit = array_sum(array_map(static fn ($line): int => $line->getDebit()->cents, $entry->getLines()));
        self::assertSame($debit, array_sum(array_map(static fn ($line): int => $line->getCredit()->cents, $entry->getLines())));
        $owed = array_values(array_filter($entry->getLines(), static fn ($line): bool => '421000' === $line->getAccount()->getNumber()));
        self::assertSame((string) preg_replace('/\D/', '', $net), (string) $owed[0]->getCredit()->cents, 'The net credited is the livre de paie’s.');
        self::assertCount(0, $crawler->filter('form[action$="/entries/post"]'), 'Posted once.');

        // The net salaries paid from BQ.
        $form = $crawler->filter('form[action$="/entries/pay"]')->first();
        self::assertSame('salaries', $form->filter('input[name="payment"]')->attr('value'));
        $client->request('POST', $this->url($dossier, '/payroll/entries/pay'), ['month' => '2026-03', 'payment' => 'salaries', 'bank' => 'BQ', '_token' => $form->filter('button[name="_token"]')->attr('value')]);
        $client->followRedirect();
        $payment = $this->only($client, $dossier, 'VIRSAL-2026-03');
        self::assertSame(['BQ', '421000', $owed[0]->getCredit()->cents], [$payment->getJournal()->getCode(), $payment->getLines()[0]->getAccount()->getNumber(), $payment->getLines()[0]->getDebit()->cents]);

        // Reopened by the teacher, the payslip cancelled: its entry leaves the brouillard, the payment stays.
        $this->signOut($client);
        $this->submitLogin($client, 'prof.martin');
        $crawler = $client->request('GET', $this->url($dossier, '/payroll/payslips'));
        $client->request('POST', $this->url($dossier, '/payroll/payslips/reopen'), ['_token' => $crawler->filter('form[action$="/reopen"] input[name="_token"]')->attr('value')]);
        $this->signOut($client);
        $this->submitLogin($client, 'ines.morel');
        $crawler = $client->request('GET', $payslip);
        $client->request('POST', $payslip.'/cancel', ['_token' => $crawler->filter('form[action$="/cancel"] input[name="_token"]')->attr('value')]);
        $client->followRedirect();
        self::assertSelectorTextContains('main', 'Calculé, à valider');
        self::assertSame([], $this->entries($client, $dossier, 'PAIE-2026-03'));
        self::assertCount(1, $this->entries($client, $dossier, 'VIRSAL-2026-03'));
    }

    private function moveTo(KernelBrowser $client, Dossier $dossier, string $day): void
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $stored = $entityManager->find(Dossier::class, $dossier->getId());
        \assert($stored instanceof Dossier);
        $stored->moveDate(new \DateTimeImmutable($day), new \DateTimeImmutable('2026-11-28 10:00'));
        $entityManager->flush();
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
