<?php

declare(strict_types=1);

namespace App\Service\Identity;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Ldap\Entry;
use Symfony\Component\Ldap\Exception\ExceptionInterface;
use Symfony\Component\Ldap\Exception\InvalidCredentialsException;
use Symfony\Component\Ldap\LdapInterface;

/**
 * The directory over LDAP, as in Formatool: bind as the service account, search `(uid=…)`, read
 * the groups whose `member` is the person's DN; the password is checked by a second bind as that DN.
 */
final readonly class LdapDirectory implements Directory
{
    public function __construct(
        private LdapInterface $ldap,
        #[Autowire(env: 'LDAP_BASE_DN')] private string $baseDn,
        #[Autowire(env: 'LDAP_SEARCH_DN')] private string $searchDn,
        #[Autowire(env: 'LDAP_SEARCH_PASSWORD')] #[\SensitiveParameter] private string $searchPassword,
        #[Autowire(env: 'LDAP_USERNAME_ATTRIBUTE')] private string $usernameAttribute,
        #[Autowire(env: 'LDAP_GROUP_OBJECT_CLASS')] private string $groupObjectClass,
        #[Autowire(env: 'PEYRA_LDAP_STABLE_ID')] private string $stableIdAttribute,
    ) {
    }

    public function find(string $login): ?DirectoryPerson
    {
        if ('' === trim($login)) {
            return null;
        }

        try {
            $this->ldap->bind($this->searchDn, $this->searchPassword);
            $filter = \sprintf('(%s=%s)', $this->usernameAttribute, $this->ldap->escape($login, '', LdapInterface::ESCAPE_FILTER));
            $entries = $this->ldap->query($this->baseDn, $filter, [
                'filter' => [$this->usernameAttribute, 'cn', 'givenName', 'sn', 'displayName', 'mail', 'uidNumber', $this->stableIdAttribute],
                'maxItems' => 2,
            ])->execute()->toArray();
            if (1 !== \count($entries)) {
                return null;
            }
            $entry = $entries[0];

            $groupFilter = \sprintf('(&(objectClass=%s)(member=%s))', $this->groupObjectClass, $this->ldap->escape($entry->getDn(), '', LdapInterface::ESCAPE_FILTER));
            $groups = [];
            foreach ($this->ldap->query($this->baseDn, $groupFilter, ['filter' => ['cn']])->execute() as $group) {
                $cn = $group->getAttribute('cn', false)[0] ?? null;
                if (null !== $cn) {
                    $groups[] = $cn;
                }
            }
        } catch (ExceptionInterface $exception) {
            throw new DirectoryUnavailable('The directory did not answer the search.', 0, $exception);
        }

        return self::personOf($entry, $groups, $this->usernameAttribute, $this->stableIdAttribute);
    }

    public function checkPassword(DirectoryPerson $person, #[\SensitiveParameter] string $password): bool
    {
        if ('' === $password) {
            return false;
        }

        try {
            $this->ldap->bind($person->dn, $password);
        } catch (InvalidCredentialsException) {
            return false;
        } catch (ExceptionInterface $exception) {
            throw new DirectoryUnavailable('The directory did not answer the bind.', 0, $exception);
        }

        return true;
    }

    /**
     * The person an entry describes. The name is « givenName sn » (in production the `cn` is the
     * login, as Formatool found), else `displayName`, else `cn`, else the login; the sort name is
     * « sn givenName » when there is a surname, the name otherwise.
     *
     * @param list<string> $groups
     */
    public static function personOf(Entry $entry, array $groups, string $usernameAttribute, string $stableIdAttribute): ?DirectoryPerson
    {
        /** @var array<string, list<string>> $attributes */
        $attributes = $entry->getAttributes();
        $read = static fn (string $name): ?string => self::text($entry->getAttribute($name, false)[0] ?? null);

        $login = $read($usernameAttribute);
        $stableId = StableId::of($attributes, $stableIdAttribute);
        if (null === $login || null === $stableId) {
            return null;
        }
        $name = trim(($read('givenName') ?? '').' '.($read('sn') ?? ''));
        if ('' === $name) {
            $name = $read('displayName') ?? $read('cn') ?? $login;
        }
        $sortName = null === $read('sn') ? $name : trim($read('sn').' '.($read('givenName') ?? ''));
        $groups = array_values(array_unique(array_map(strtolower(...), $groups)));
        sort($groups);

        return new DirectoryPerson($entry->getDn(), $login, $stableId, $name, $read('mail'), $groups, $sortName);
    }

    private static function text(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }
        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
