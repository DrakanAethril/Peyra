<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\SignsIn;
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
