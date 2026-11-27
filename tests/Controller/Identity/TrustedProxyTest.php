<?php

declare(strict_types=1);

namespace App\Tests\Controller\Identity;

use App\Entity\Identity\LoginEvent;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorageFactory;

/**
 * Production answers behind nginx (README of the plan, § 9), which says who the client is and that
 * they came in HTTPS. `.env.test` trusts 192.0.2.10 as that proxy: from it, the forwarded address,
 * scheme and host are believed - the sign-in journal and the limiters see the student, the session
 * cookie is `secure`; from anywhere else the same headers are ignored. Production also forces the
 * cookie `secure` whatever the request says (ProductionConfigurationTest).
 */
final class TrustedProxyTest extends WebTestCase
{
    use SignsIn;

    private const array THROUGH_NGINX = [
        'REMOTE_ADDR' => '192.0.2.10',
        'HTTP_X_FORWARDED_FOR' => '198.51.100.23',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_HOST' => 'pgi.example.org',
        'HTTP_X_FORWARDED_PORT' => '443',
    ];

    public function testATrustedProxySaysWhoTheClientIsAndThatTheyCameInHttps(): void
    {
        $client = static::createClient([], self::THROUGH_NGINX);
        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        $request = $client->getRequest();
        self::assertSame('198.51.100.23', $request->getClientIp());
        self::assertTrue($request->isSecure());
        self::assertSame('https://pgi.example.org', $request->getSchemeAndHttpHost());
        self::assertSame(['secure' => '1', 'samesite' => 'lax', 'httponly' => '1'], $this->nativeSessionCookie($client));

        $this->submitLogin($client, 'ines.morel');
        self::assertResponseRedirects('/');
        self::assertSame('198.51.100.23', $this->lastLoginEvent($client, 'ines.morel')->getIp());
    }

    public function testForwardedHeadersFromAnyoneElseAreIgnored(): void
    {
        $client = static::createClient([], ['REMOTE_ADDR' => '203.0.113.5'] + self::THROUGH_NGINX);
        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        $request = $client->getRequest();
        self::assertSame('203.0.113.5', $request->getClientIp());
        self::assertFalse($request->isSecure());
        self::assertSame('http://localhost', $request->getSchemeAndHttpHost());
        self::assertSame(['secure' => '0', 'samesite' => 'lax', 'httponly' => '1'], $this->nativeSessionCookie($client));

        $this->submitLogin($client, 'ines.morel', 'not-the-password');
        self::assertSame('203.0.113.5', $this->lastLoginEvent($client, 'ines.morel')->getIp());
    }

    /**
     * The session cookie's flags as the real (native) session storage sets them for this request.
     * The suite runs on the mock storage, which never resolves `cookie_secure: auto`: the native
     * factory is built here from the application's own session options, as FrameworkBundle does.
     *
     * @return array{secure: string, samesite: string, httponly: string}
     */
    private function nativeSessionCookie(KernelBrowser $client): array
    {
        $options = $client->getContainer()->getParameter('session.storage.options');
        self::assertIsArray($options);
        self::assertSame('auto', $options['cookie_secure'] ?? null);
        $saved = ['secure' => ini_get('session.cookie_secure'), 'samesite' => ini_get('session.cookie_samesite'), 'httponly' => ini_get('session.cookie_httponly')];

        try {
            (new NativeSessionStorageFactory($options, null, null, true))->createStorage($client->getRequest());

            return ['secure' => (string) ini_get('session.cookie_secure'), 'samesite' => (string) ini_get('session.cookie_samesite'), 'httponly' => (string) ini_get('session.cookie_httponly')];
        } finally {
            foreach ($saved as $name => $value) {
                ini_set('session.cookie_'.$name, (string) $value);
            }
        }
    }

    private function lastLoginEvent(KernelBrowser $client, string $login): LoginEvent
    {
        $event = $client->getContainer()->get('doctrine')->getRepository(LoginEvent::class)
            ->findOneBy(['login' => $login], ['id' => 'DESC']);
        self::assertInstanceOf(LoginEvent::class, $event);

        return $event;
    }
}
