<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Ldap\Adapter\ExtLdap\Adapter;

/**
 * The ext-ldap adapter, as in Formatool: the TLS options are set only when a CA certificate is
 * given (production's LDAPS), never for the plain LDAP of the development directory.
 */
final class LdapAdapterFactory
{
    public static function create(string $host, int $port, string $encryption, string $tlsCaCertPath = ''): Adapter
    {
        if ('' !== $tlsCaCertPath) {
            putenv('LDAPTLS_CACERT='.$tlsCaCertPath);
            putenv('LDAPTLS_REQCERT=demand');
        }

        return new Adapter([
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'options' => [
                'protocol_version' => 3,
                'referrals' => false,
            ],
        ]);
    }
}
