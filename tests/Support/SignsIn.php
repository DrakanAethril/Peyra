<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Identity\User;
use App\Repository\Identity\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Signs in through the real form (GET /login for the token, then POST), with the test directory's
 * password unless told otherwise. In a file of its own: ParaTest hands out files, not classes.
 */
trait SignsIn
{
    private function submitLogin(KernelBrowser $client, string $login, string $password = InMemoryDirectory::PASSWORD): void
    {
        $crawler = $client->request('GET', '/login');
        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/login', ['_username' => $login, '_password' => $password, '_csrf_token' => $token]);
    }

    /** Follows the « Se déconnecter » link of the menu (it carries its token) from the account's home. */
    private function signOut(KernelBrowser $client): void
    {
        $crawler = $client->request('GET', '/');
        if ($client->getResponse()->isRedirect()) {
            $crawler = $client->followRedirect();
        }
        $client->request('GET', (string) $crawler->filter('a[href^="/logout"]')->attr('href'));
    }

    /** The message the sign-in page shows after a refused attempt, or null. */
    private function loginError(KernelBrowser $client): ?string
    {
        if ($client->getResponse()->isRedirect()) {
            $client->followRedirect();
        }
        $alert = $client->getCrawler()->filter('#login-error');

        return 0 === $alert->count() ? null : trim($alert->text());
    }

    private function account(KernelBrowser $client, string $login): ?User
    {
        return $client->getContainer()->get(UserRepository::class)->findOneBy(['login' => $login]);
    }

    private function directory(KernelBrowser $client): InMemoryDirectory
    {
        $directory = $client->getContainer()->get(self::directoryServiceId());
        \assert($directory instanceof InMemoryDirectory);

        return $directory;
    }

    /**
     * Declared `string` on purpose: PHPStan resolves service ids against the dev container, where
     * the directory is LDAP and the test one does not exist.
     */
    private static function directoryServiceId(): string
    {
        return InMemoryDirectory::class;
    }
}
