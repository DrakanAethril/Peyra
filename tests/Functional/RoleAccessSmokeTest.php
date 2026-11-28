<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Accounting\Entry;
use App\Entity\Accounting\Journal;
use App\Entity\Identity\User;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Accounting\JournalType;
use App\Enum\Dossier\DossierKind;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Repository\Accounting\AccountRepository;
use App\Repository\Accounting\EntrySubscriptionRepository;
use App\Repository\Identity\SchoolYearRepository;
use App\Service\Accounting\Ledger\EntryDraft;
use App\Service\Accounting\Ledger\EntryLineDraft;
use App\Service\Accounting\Ledger\Ledger;
use App\Service\Accounting\Subscription\SubscriptionDraft;
use App\Service\Accounting\Subscription\SubscriptionSheet;
use App\Service\Accounting\Template\TemplateDraft;
use App\Service\Accounting\Template\TemplateLineDraft;
use App\Service\Accounting\Template\TemplateSheet;
use App\Service\Dossier\DossierContext;
use App\Service\Identity\SchoolClasses;
use App\Service\Shared\Money;
use App\Tests\Support\BuildsSchool;
use App\Tests\Support\RegulatoryFixtures;
use App\Tests\Support\SavesDossiers;
use App\Tests\Support\SignsIn;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Each main screen as each kind of account (README of the plan, § 6): the cheapest place to notice
 * that a screen gained or lost a role by accident. A refusal to a signed-in person is a 404; the
 * refused account (enzo.petit, outside the BTS CG) never gets a session, so it is sent to the
 * sign-in page like anybody else. Extend the table with every new screen.
 */
final class RoleAccessSmokeTest extends WebTestCase
{
    use SavesDossiers;
    use BuildsSchool;
    use SignsIn;

    /** login per role; `refused` is an account the entry rule turns away */
    private const array ACCOUNTS = [
        'student' => 'ines.morel',
        'teacher' => 'prof.durand',
        'admin' => 'prof.martin',
        'refused' => 'enzo.petit',
    ];

    /** @return iterable<string, array{string, array<string, int>}> url => expected status per role */
    public static function screens(): iterable
    {
        $public = ['student' => 200, 'teacher' => 200, 'admin' => 200, 'refused' => 200];
        yield 'À propos' => ['/about', $public];
        yield 'Santé' => ['/health', $public];
        yield 'Accueil' => ['/', ['student' => 302, 'teacher' => 302, 'admin' => 302, 'refused' => 302]];
        yield 'Mes dossiers' => ['/dossiers', ['student' => 200, 'teacher' => 404, 'admin' => 404, 'refused' => 302]];
        yield 'Accueil enseignant' => ['/teacher', ['student' => 404, 'teacher' => 200, 'admin' => 200, 'refused' => 302]];
        yield 'Enseignants de la filière' => ['/teacher/admin/teachers', ['student' => 404, 'teacher' => 404, 'admin' => 200, 'refused' => 302]];
        yield 'Administrateurs' => ['/teacher/admin/administrators', ['student' => 404, 'teacher' => 404, 'admin' => 200, 'refused' => 302]];
        yield 'Candidats administrateurs' => ['/teacher/admin/administrators/candidates?q=pr', ['student' => 404, 'teacher' => 404, 'admin' => 200, 'refused' => 302]];
        yield 'Journal des connexions' => ['/teacher/admin/logins', ['student' => 404, 'teacher' => 404, 'admin' => 200, 'refused' => 302]];
        yield 'Journal d’administration' => ['/teacher/admin/journal', ['student' => 404, 'teacher' => 404, 'admin' => 200, 'refused' => 302]];
        $teachers = ['student' => 404, 'teacher' => 200, 'admin' => 200, 'refused' => 302];
        $admins = ['student' => 404, 'teacher' => 404, 'admin' => 200, 'refused' => 302];
        yield 'Classes de l’année' => ['/teacher/classes', $teachers];
        yield 'À placer' => ['/teacher/classes/unplaced', $teachers];
        yield 'Sortis de la filière' => ['/teacher/classes/unplaced?show=departed', $teachers];
        yield 'Passage de classe' => ['/teacher/classes/promotion', $teachers];
        yield 'Années précédentes' => ['/teacher/classes/past', $teachers];
        yield 'Recherche d’étudiants' => ['/teacher/students/search?q=mo', $teachers];
        yield 'Années scolaires' => ['/teacher/admin/years', $admins];
        yield 'Classes (administration)' => ['/teacher/admin/classes', $admins];
        yield 'Correspondance avec l’annuaire' => ['/teacher/admin/directory', $admins];
        yield 'Recherche d’enseignants' => ['/teacher/admin/classes/teachers/search?q=pr', $admins];
        yield 'Paramètres réglementaires' => ['/teacher/regulatory', $teachers];
        yield 'Bibliothèque › Dossiers modèles' => ['/teacher/library', $teachers];
        yield 'Bibliothèque › Dossiers modèles, filtrés' => ['/teacher/library?shelf=mine&q=vasseur', $teachers];
        yield 'Bibliothèque › Partages' => ['/teacher/library/shares', $teachers];
        yield 'Nouveau dossier modèle' => ['/teacher/library/new', $teachers];
        yield 'Nouveau dossier modèle vide' => ['/teacher/library/new/empty', $teachers];
    }

    /** The sheet of a regulatory value and « Nouvelle valeur datée », on a value written for the test. */
    public function testTheScreensOfARegulatoryValue(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist(RegulatoryFixtures::fromFile('social.smic_hourly', '2026-01-01', '12.02', unit: 'EUR/heure'));
        $em->flush();

        $screens = [
            '/teacher/regulatory/social.smic_hourly' => ['student' => 404, 'teacher' => 200, 'admin' => 200, 'refused' => 302],
            '/teacher/regulatory/social.smic_hourly/new' => ['student' => 404, 'teacher' => 404, 'admin' => 200, 'refused' => 302],
        ];
        foreach ($screens as $url => $expected) {
            $actual = [];
            foreach (self::ACCOUNTS as $role => $login) {
                self::ensureKernelShutdown();
                $client = static::createClient();
                $this->submitLogin($client, $login);
                $client->request('GET', $url);
                $actual[$role] = $client->getResponse()->getStatusCode();
            }
            self::assertSame($expected, $actual, $url);
        }
    }

    /**
     * The screens of a class, a student and a passage, on a school built for the test: last year's
     * CG1 names this year's CG2 as its next class, and ines.morel is in CG1 this year.
     */
    public function testTheScreensOfAClassAndOfAStudent(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->submitLogin($client, self::ACCOUNTS['admin']);
        $martin = $this->account($client, self::ACCOUNTS['admin']);
        \assert($martin instanceof User);
        [, $lastCg1] = $this->buildYear($client, $martin, 2025, false);
        [, $cg1, $cg2] = $this->buildYear($client, $martin, 2026, true);
        $lastCg1->setNextClass($cg2);
        $client->getContainer()->get('doctrine')->getManager()->flush();
        $this->signOut($client);
        $this->submitLogin($client, self::ACCOUNTS['student']);
        $ines = (int) $this->account($client, self::ACCOUNTS['student'])?->getId();

        $teachers = ['student' => 404, 'teacher' => 200, 'admin' => 200, 'refused' => 302];
        $admins = ['student' => 404, 'teacher' => 404, 'admin' => 200, 'refused' => 302];
        $screens = [
            '/teacher/classes/'.$cg1->getId() => $teachers,
            '/teacher/classes/'.$lastCg1->getId() => $teachers,
            '/teacher/students/'.$ines => $teachers,
            '/teacher/classes/promotion/'.$lastCg1->getId() => $teachers,
            '/teacher/classes/promotion/'.$lastCg1->getId().'/summary?step=2' => $teachers,
            '/teacher/classes/promotion/'.$cg1->getId() => ['student' => 404, 'teacher' => 404, 'admin' => 404, 'refused' => 302],
            '/teacher/admin/classes/'.$cg1->getId() => $admins,
        ];
        foreach ($screens as $url => $expected) {
            $actual = [];
            foreach (self::ACCOUNTS as $role => $login) {
                self::ensureKernelShutdown();
                $client = static::createClient();
                $this->submitLogin($client, $login);
                $client->request('GET', $url);
                $actual[$role] = $client->getResponse()->getStatusCode();
            }
            self::assertSame($expected, $actual, $url);
        }
    }

    /**
     * A student's dossier (lot 1, steps 1.1, 1.2a and 1.2b): ines.morel holds it, prof.durand teaches her
     * class this year, prof.martin - an administrator - does not: no administrator enters a
     * student's dossier outside their classes. Its plan of accounts, journals and third parties included.
     */
    public function testTheScreensOfADossier(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->submitLogin($client, self::ACCOUNTS['teacher']);
        $this->signOut($client);
        $this->submitLogin($client, self::ACCOUNTS['admin']);
        $martin = $this->account($client, self::ACCOUNTS['admin']);
        \assert($martin instanceof User);
        [, $cg1] = $this->buildYear($client, $martin, 2026, true);
        $durand = $this->account($client, self::ACCOUNTS['teacher']);
        \assert($durand instanceof User);
        $client->getContainer()->get(SchoolClasses::class)->addTeacher($cg1, $durand, $martin, new \DateTimeImmutable('2026-11-27 10:00'));
        $client->getContainer()->get('doctrine')->getManager()->flush();
        $this->signOut($client);
        $this->submitLogin($client, self::ACCOUNTS['student']);
        $ines = $this->account($client, self::ACCOUNTS['student']);
        \assert($ines instanceof User);
        $saved = $this->savedDossier(DossierKind::Student, $ines, $client->getContainer()->get(SchoolYearRepository::class)->findCurrent());
        $this->installPlan($saved);
        $client->getContainer()->get(DossierContext::class)->within($saved, static function () use ($client, $saved): void {
            $bank = $client->getContainer()->get(AccountRepository::class)->findOneByNumber($saved, '512100');
            $client->getContainer()->get(EntityManagerInterface::class)->persist(new Journal($saved, 'BQ1', 'Crédit Coopératif', JournalType::Bank, $bank));
            $scierie = new ThirdParty($saved, 'F0001', ThirdPartyKind::Supplier, 'Scierie du Haut-Forez');
            $scierie->keepOn($client->getContainer()->get(AccountRepository::class)->findOneByNumber($saved, '401000'), '401SCIE');
            $client->getContainer()->get(EntityManagerInterface::class)->persist($scierie);
            $client->getContainer()->get(EntityManagerInterface::class)->flush();
            $draft = new TemplateDraft();
            [$draft->code, $draft->label, $draft->journal] = ['VIRSCIE', 'Virement à la Scierie', 'BQ1'];
            $draft->lines = [new TemplateLineDraft('401SCIE', '', '', 'debit', 'typed')];
            $sheet = $client->getContainer()->get(TemplateSheet::class);
            $sheet->create($saved, $sheet->check($draft, $saved) ?? throw new \LogicException(implode(' ', $draft->errors)), 'test');
            $client->getContainer()->get(EntityManagerInterface::class)->flush();
            $subscription = new SubscriptionDraft();
            [$subscription->label, $subscription->template, $subscription->day, $subscription->firstMonth, $subscription->occurrences, $subscription->pattern, $subscription->target] = ['Virement mensuel', 'VIRSCIE', '10', '2026-02-01', '6', 'VIR-{MM}', 'pending'];
            $subscriptions = $client->getContainer()->get(SubscriptionSheet::class);
            $subscriptions->create($saved, $subscriptions->check($subscription, $saved) ?? throw new \LogicException(implode(' ', $subscription->errors)), 'test');
            $client->getContainer()->get(EntityManagerInterface::class)->flush();
        });
        $subscriptionId = $client->getContainer()->get(DossierContext::class)->within($saved, static fn (): ?int => $client->getContainer()->get(EntrySubscriptionRepository::class)->findByDossier($saved)[0]->getId());
        $entry = $client->getContainer()->get(DossierContext::class)->within($saved, static fn (): Entry => $client->getContainer()->get(Ledger::class)->draft($saved, new EntryDraft('BQ1', new \DateTimeImmutable('2026-01-20'), 'VIR-SCIE', 'Virement Scierie', [
            EntryLineDraft::debit('401000', Money::cents(1_000), '', 'F0001'),
            EntryLineDraft::credit('512100', Money::cents(1_000)),
        ]), 'test'));
        $dossier = (int) $saved->getId();

        $holders = ['student' => 200, 'teacher' => 200, 'admin' => 404, 'refused' => 302];
        $screens = [
            '', '/company',
            '/accounting/accounts', '/accounting/accounts?class=6&q=achats', '/accounting/accounts/601000',
            '/accounting/accounts/new', '/accounting/accounts/import', '/accounting/accounts/export.csv',
            '/accounting/accounts/search?q=51', '/accounting/journals', '/accounting/journals/new', '/accounting/journals/BQ1',
            '/accounting/third-parties', '/accounting/third-parties?kind=supplier&q=scie', '/accounting/third-parties/F0001',
            '/accounting/third-parties/new', '/accounting/third-parties/import', '/accounting/third-parties/export.csv',
            '/accounting/accounts/search?q=401S',
            '/accounting/entry', '/accounting/entry?journal=BQ1&period=2026-01-01', '/accounting/entries/'.$entry->getId(),
            '/accounting/templates', '/accounting/templates/new', '/accounting/templates/VIRSCIE',
            '/accounting/subscriptions', '/accounting/subscriptions/new', '/accounting/subscriptions/'.$subscriptionId,
            '/accounting/brouillard', '/accounting/brouillard/export.csv', '/accounting/consultation', '/accounting/consultation?account=401SCIE',
            '/accounting/consultation?account=512100&scope=validated', '/accounting/consultation/export.xlsx?account=512100',
            '/accounting/consultation/peek?account=401SCIE', '/accounting/entries', '/accounting/entries?journal=BQ1&period=2026-01-01',
            '/accounting/entries/export.csv?journal=BQ1', '/accounting/entries/'.$entry->getId().'?return=/d/'.$saved->getId().'/accounting/brouillard',
            '/accounting/editions/journals', '/accounting/editions/journals?journal=BQ1&scope=validated', '/accounting/editions/journals.pdf',
            '/accounting/editions/journals/export.xlsx', '/accounting/editions/general-ledger', '/accounting/editions/general-ledger?account_from=4&account_to=5',
            '/accounting/editions/general-ledger.pdf', '/accounting/editions/general-ledger/export.csv', '/accounting/editions/trial-balance',
            '/accounting/editions/trial-balance?from=2026-02-01', '/accounting/editions/trial-balance.pdf', '/accounting/editions/trial-balance/export.xlsx',
            '/accounting/editions/third-party-balance', '/accounting/editions/third-party-balance?kind=supplier', '/accounting/editions/third-party-balance.pdf',
            '/accounting/editions/third-party-balance/export.csv', '/accounting/editions/aged-balance', '/accounting/editions/aged-balance?kind=supplier',
            '/accounting/editions/aged-balance.pdf?kind=supplier', '/accounting/editions/aged-balance/export.xlsx',
            '/search', '/search?q=ach', '/search?q=401SCIE&here=/d/'.$saved->getId().'/accounting/brouillard',
            '/accounting/lettering', '/accounting/lettering?account=401SCIE', '/accounting/lettering?account=401SCIE&all=1&auto=amount',
            '/accounting/lettering?account=401000', '/accounting/opening', '/accounting/opening?exercise=2026',
            '/users', '/audit', '/audit?role=teacher&action=accounting.entry_validated&q=ACH', '/audit/export.csv', '/audit/export.xlsx',
        ];
        foreach (array_map(static fn (string $path): string => '/d/'.$dossier.$path, $screens) as $url) {
            $actual = [];
            foreach (self::ACCOUNTS as $role => $login) {
                self::ensureKernelShutdown();
                $client = static::createClient();
                $this->submitLogin($client, $login);
                $client->request('GET', $url);
                $actual[$role] = $client->getResponse()->getStatusCode();
            }
            self::assertSame($holders, $actual, $url);
        }

        // The guided entry: hidden from the student at the default level (« Avertissements »), open to their teacher.
        $actual = [];
        foreach (self::ACCOUNTS as $role => $login) {
            self::ensureKernelShutdown();
            $client = static::createClient();
            $this->submitLogin($client, $login);
            $client->request('GET', '/d/'.$dossier.'/accounting/entry/guided');
            $actual[$role] = $client->getResponse()->getStatusCode();
        }
        self::assertSame(['student' => 404, 'teacher' => 200, 'admin' => 404, 'refused' => 302], $actual, '/accounting/entry/guided');
    }

    /**
     * A template of the library: prof.durand's own (prof.martin, an administrator, is not let into
     * it), and one of the common library, which every teacher reads and administrators delete.
     */
    public function testTheScreensOfATemplate(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->submitLogin($client, self::ACCOUNTS['teacher']);
        $durand = $this->account($client, self::ACCOUNTS['teacher']);
        \assert($durand instanceof User);
        $own = (int) $this->savedDossier(DossierKind::Template, $durand)->getId();
        $common = (int) $this->savedDossier(DossierKind::Template, null)->getId();
        $this->signOut($client);
        $this->submitLogin($client, self::ACCOUNTS['admin']);
        $this->signOut($client);
        $this->submitLogin($client, self::ACCOUNTS['student']);
        $ines = $this->account($client, self::ACCOUNTS['student']);
        \assert($ines instanceof User);
        $student = (int) $this->savedDossier(DossierKind::Student, $ines)->getId();

        $table = [
            '/teacher/library/'.$own => ['student' => 404, 'teacher' => 200, 'admin' => 404, 'refused' => 302],
            '/teacher/library/'.$own.'/delete' => ['student' => 404, 'teacher' => 200, 'admin' => 404, 'refused' => 302],
            '/teacher/library/'.$own.'/colleagues?q=ma' => ['student' => 404, 'teacher' => 200, 'admin' => 404, 'refused' => 302],
            '/teacher/library/'.$common => ['student' => 404, 'teacher' => 200, 'admin' => 200, 'refused' => 302],
            '/teacher/library/'.$common.'/delete' => ['student' => 404, 'teacher' => 404, 'admin' => 200, 'refused' => 302],
            '/teacher/library/'.$common.'/colleagues?q=ma' => ['student' => 404, 'teacher' => 404, 'admin' => 404, 'refused' => 302],
            '/teacher/library/'.$student => ['student' => 404, 'teacher' => 404, 'admin' => 404, 'refused' => 302],
        ];
        foreach ($table as $url => $expected) {
            $actual = [];
            foreach (self::ACCOUNTS as $role => $login) {
                self::ensureKernelShutdown();
                $client = static::createClient();
                $this->submitLogin($client, $login);
                $client->request('GET', $url);
                $actual[$role] = $client->getResponse()->getStatusCode();
            }
            self::assertSame($expected, $actual, $url);
        }
    }

    /** @param array<string, int> $expected */
    #[DataProvider('screens')]
    public function testEachRoleGetsWhatTheTableSays(string $url, array $expected): void
    {
        $actual = [];
        foreach (self::ACCOUNTS as $role => $login) {
            self::ensureKernelShutdown();
            $client = static::createClient();
            $this->submitLogin($client, $login);
            $client->request('GET', $url);
            $actual[$role] = $client->getResponse()->getStatusCode();
        }

        self::assertSame($expected, $actual);
    }

    public function testTheRefusedAccountIsSentToTheSignInPage(): void
    {
        $client = static::createClient();
        $this->submitLogin($client, self::ACCOUNTS['refused']);
        $client->request('GET', '/teacher');

        self::assertResponseRedirects('/login');
    }
}
