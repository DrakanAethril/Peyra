<?php

declare(strict_types=1);

namespace App\Service\Identity;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Ldap\Exception\ExceptionInterface;
use Symfony\Component\Ldap\Exception\InvalidCredentialsException;
use Symfony\Component\Ldap\LdapInterface;

/**
 * Whether the directory answers and the service account binds (`app:ldap:check`, docs/production.md):
 * the first thing to ask when nobody can sign in. It says what is configured and what happened, and
 * nothing secret - never the password, and the directory's own error message only, which names no
 * credential.
 */
final readonly class DirectoryProbe
{
    public function __construct(
        private LdapInterface $ldap,
        #[Autowire(env: 'LDAP_HOST')] private string $host,
        #[Autowire(env: 'int:LDAP_PORT')] private int $port,
        #[Autowire(env: 'LDAP_ENCRYPTION')] private string $encryption,
        #[Autowire(env: 'LDAP_TLS_CA_CERT_PATH')] private string $caCertPath,
        #[Autowire(env: 'LDAP_BASE_DN')] private string $baseDn,
        #[Autowire(env: 'LDAP_SEARCH_DN')] private string $searchDn,
        #[Autowire(env: 'LDAP_SEARCH_PASSWORD')] #[\SensitiveParameter] private string $searchPassword,
    ) {
    }

    /** What is configured, safe to print: no password, only whether there is one. */
    public function configuration(): DirectoryProbeConfiguration
    {
        $certificate = match (true) {
            '' === trim($this->caCertPath) => null,
            is_file($this->caCertPath) && is_readable($this->caCertPath) => true,
            default => false,
        };

        return new DirectoryProbeConfiguration(
            \sprintf('%s://%s:%d', 'ssl' === $this->encryption ? 'ldaps' : 'ldap', $this->host, $this->port),
            $this->encryption,
            '' === trim($this->caCertPath) ? null : $this->caCertPath,
            $certificate,
            $this->baseDn,
            $this->searchDn,
            '' !== $this->searchPassword,
        );
    }

    /** Binds as the service account, then reads the search base itself. */
    public function probe(): DirectoryProbeResult
    {
        if ('' === trim($this->host)) {
            return new DirectoryProbeResult(DirectoryProbeOutcome::NotConfigured);
        }

        try {
            $this->ldap->bind($this->searchDn, $this->searchPassword);
        } catch (InvalidCredentialsException) {
            return new DirectoryProbeResult(DirectoryProbeOutcome::Refused);
        } catch (ExceptionInterface $exception) {
            return new DirectoryProbeResult(DirectoryProbeOutcome::Unreachable, $exception->getMessage());
        }

        try {
            $this->ldap->query($this->baseDn, '(objectClass=*)', ['scope' => 'base', 'filter' => ['objectClass']])->execute()->toArray();
        } catch (ExceptionInterface $exception) {
            return new DirectoryProbeResult(DirectoryProbeOutcome::BaseUnreadable, $exception->getMessage());
        }

        return new DirectoryProbeResult(DirectoryProbeOutcome::Bound);
    }
}
