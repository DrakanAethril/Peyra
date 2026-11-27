<?php

declare(strict_types=1);

namespace App\Tests\Controller\Identity;

use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The two links to Formatool (README of the plan, § 9): the forgotten password of the sign-in page,
 * whose procedure is Formatool's, and the « Formatool » entry of the avatar menu. Empty
 * FORMATOOL_URL (development, and this suite by default): neither is drawn.
 */
final class FormatoolLinkTest extends WebTestCase
{
    use SignsIn;

    private ?string $originalEnv = null;
    private ?string $originalServer = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEnv = \is_string($_ENV['FORMATOOL_URL'] ?? null) ? $_ENV['FORMATOOL_URL'] : null;
        $this->originalServer = \is_string($_SERVER['FORMATOOL_URL'] ?? null) ? $_SERVER['FORMATOOL_URL'] : null;
    }

    /**
     * Restores the variable rather than unsetting it: `.env` declares it empty, and a test after
     * this one in the same process would otherwise find no FORMATOOL_URL at all and fail to boot
     * the sign-in page (« Environment variable not found »).
     */
    protected function tearDown(): void
    {
        unset($_ENV['FORMATOOL_URL'], $_SERVER['FORMATOOL_URL']);
        if (null !== $this->originalEnv) {
            $_ENV['FORMATOOL_URL'] = $this->originalEnv;
        }
        if (null !== $this->originalServer) {
            $_SERVER['FORMATOOL_URL'] = $this->originalServer;
        }
        parent::tearDown();
    }

    public function testWithoutAnAddressTheSignInPageSaysWhomToAsk(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        self::assertSelectorTextContains('.px-public__note', 'Mot de passe oublié : adressez-vous au service informatique de l’établissement.');
        self::assertSelectorNotExists('.px-public__note a');

        $this->submitLogin($client, 'prof.durand');
        $client->request('GET', '/teacher');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.px-usermenu a[href="/about"]');
        self::assertSelectorNotExists('.px-usermenu a[href^="https://"]');
    }

    public function testTheForgottenPasswordGoesThroughFormatoolAndTheMenuLeadsBack(): void
    {
        $client = $this->clientWithFormatool();
        $client->request('GET', '/login');

        self::assertSelectorExists('.px-public__note a[href="https://formatool.example.org/login/magic-link"]');
        self::assertSelectorTextContains('.px-public__note a', 'Mot de passe oublié');

        $this->submitLogin($client, 'ines.morel');
        $client->request('GET', '/dossiers');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.px-usermenu a[role="menuitem"][href="https://formatool.example.org/"]');
        self::assertSelectorTextContains('.px-usermenu a[href="https://formatool.example.org/"]', 'Formatool');
    }

    private function clientWithFormatool(): KernelBrowser
    {
        $_ENV['FORMATOOL_URL'] = $_SERVER['FORMATOOL_URL'] = 'https://formatool.example.org';

        return static::createClient();
    }
}
