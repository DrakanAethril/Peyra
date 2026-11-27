<?php

declare(strict_types=1);

namespace App\Service\Identity;

/** The directory's configuration as `app:ldap:check` prints it: nothing secret in it. */
final readonly class DirectoryProbeConfiguration
{
    public function __construct(
        public string $address,
        public string $encryption,
        public ?string $caCertPath,
        /** null: none configured; false: configured but not a readable file */
        public ?bool $caCertReadable,
        public string $baseDn,
        public string $searchDn,
        public bool $hasSearchPassword,
    ) {
    }
}
