<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Identity\User;
use App\Enum\Dossier\DossierKind;
use App\Repository\Identity\SchoolYearRepository;
use App\Service\Identity\SchoolClasses;
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
     * A student's dossier (lot 1, step 1.1): ines.morel holds it, prof.durand teaches her class this
     * year, prof.martin - an administrator - does not: no administrator enters a student's dossier
     * outside their classes.
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
        $dossier = (int) $this->savedDossier(DossierKind::Student, $ines, $client->getContainer()->get(SchoolYearRepository::class)->findCurrent())->getId();

        $holders = ['student' => 200, 'teacher' => 200, 'admin' => 404, 'refused' => 302];
        foreach (['/d/'.$dossier, '/d/'.$dossier.'/company'] as $url) {
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
