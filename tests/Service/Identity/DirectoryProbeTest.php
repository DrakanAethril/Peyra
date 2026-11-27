<?php

declare(strict_types=1);

namespace App\Tests\Service\Identity;

use App\Security\LdapAdapterFactory;
use App\Service\Identity\DirectoryProbe;
use App\Service\Identity\DirectoryProbeOutcome;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Ldap\Ldap;

/**
 * The probe of `app:ldap:check` against the development directory, which CI starts too: each way
 * a production configuration can be wrong gives its own answer.
 */
final class DirectoryProbeTest extends TestCase
{
    public function testTheDevelopmentDirectoryAnswersAndTheServiceAccountBinds(): void
    {
        $result = $this->probe()->probe();

        self::assertSame(DirectoryProbeOutcome::Bound, $result->outcome);
        self::assertNull($result->detail);
    }

    public function testAWrongServicePasswordIsARefusalNotAnOutage(): void
    {
        self::assertSame(DirectoryProbeOutcome::Refused, $this->probe(password: 'not-the-password')->probe()->outcome);
    }

    public function testAClosedPortIsAnOutage(): void
    {
        $result = $this->probe(port: 1)->probe();

        self::assertSame(DirectoryProbeOutcome::Unreachable, $result->outcome);
        self::assertNotNull($result->detail);
    }

    public function testASearchBaseThatDoesNotExistIsSaid(): void
    {
        self::assertSame(DirectoryProbeOutcome::BaseUnreadable, $this->probe(baseDn: 'dc=nowhere,dc=lan')->probe()->outcome);
    }

    public function testNoHostIsNoDirectory(): void
    {
        self::assertSame(DirectoryProbeOutcome::NotConfigured, $this->probe(host: '')->probe()->outcome);
    }

    public function testTheConfigurationItPrintsHoldsNoPassword(): void
    {
        $configuration = $this->probe(caCertPath: '/nowhere/ldap-ca.pem')->configuration();

        self::assertSame(\sprintf('ldap://%s:%s', self::env('LDAP_HOST'), self::env('LDAP_PORT')), $configuration->address);
        self::assertFalse($configuration->caCertReadable);
        self::assertTrue($configuration->hasSearchPassword);
        self::assertStringNotContainsString(self::env('LDAP_SEARCH_PASSWORD'), serialize($configuration));
        self::assertNull($this->probe()->configuration()->caCertReadable);
    }

    private function probe(?string $host = null, ?int $port = null, ?string $password = null, ?string $baseDn = null, string $caCertPath = ''): DirectoryProbe
    {
        $host ??= self::env('LDAP_HOST');
        $port ??= (int) self::env('LDAP_PORT');

        return new DirectoryProbe(
            new Ldap(LdapAdapterFactory::create('' === $host ? 'localhost' : $host, $port, self::env('LDAP_ENCRYPTION'))),
            $host,
            $port,
            self::env('LDAP_ENCRYPTION'),
            $caCertPath,
            $baseDn ?? self::env('LDAP_BASE_DN'),
            self::env('LDAP_SEARCH_DN'),
            $password ?? self::env('LDAP_SEARCH_PASSWORD'),
        );
    }

    private static function env(string $name): string
    {
        return \is_string($value = getenv($name)) ? $value : throw new \RuntimeException($name.' is not set: the test reads the development directory.');
    }
}
