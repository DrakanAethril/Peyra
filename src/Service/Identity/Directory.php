<?php

declare(strict_types=1);

namespace App\Service\Identity;

/**
 * The establishment's directory, read-only: Peyra never writes to it. LdapDirectory in every
 * environment but test, where an in-memory one stands in (tests/Support/InMemoryDirectory).
 */
interface Directory
{
    /**
     * The person whose login is exactly this one, or null.
     *
     * @throws DirectoryUnavailable when the directory does not answer
     */
    public function find(string $login): ?DirectoryPerson;

    /**
     * Whether the password opens the person's own account (a bind as their DN). An empty password
     * is always false: an anonymous bind would succeed.
     *
     * @throws DirectoryUnavailable when the directory does not answer
     */
    public function checkPassword(DirectoryPerson $person, #[\SensitiveParameter] string $password): bool;
}
