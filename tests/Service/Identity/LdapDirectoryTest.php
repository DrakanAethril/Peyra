<?php

declare(strict_types=1);

namespace App\Tests\Service\Identity;

use App\Security\LdapAdapterFactory;
use App\Service\Identity\LdapDirectory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Ldap\Entry;
use Symfony\Component\Ldap\Ldap;

/**
 * LdapDirectory against the fictitious directory of the development stack (frankenphp/ldap/), which
 * CI starts too: what the in-memory directory of the other tests stands in for.
 */
final class LdapDirectoryTest extends TestCase
{
    private LdapDirectory $directory;

    protected function setUp(): void
    {
        $env = static fn (string $name): string => \is_string($value = getenv($name)) ? $value : throw new \RuntimeException($name.' is not set: the test reads the development directory.');
        $this->directory = new LdapDirectory(
            new Ldap(LdapAdapterFactory::create($env('LDAP_HOST'), (int) $env('LDAP_PORT'), $env('LDAP_ENCRYPTION'))),
            $env('LDAP_BASE_DN'),
            $env('LDAP_SEARCH_DN'),
            $env('LDAP_SEARCH_PASSWORD'),
            'uid',
            'groupOfNames',
            'entryUUID',
        );
    }

    public function testFindsAPersonWithTheirGroupsAndStableId(): void
    {
        $person = $this->directory->find('ines.morel');

        self::assertNotNull($person);
        self::assertSame('ines.morel', $person->login);
        self::assertSame('Inès Morel', $person->displayName);
        self::assertSame('ines.morel@peyra.test', $person->email);
        self::assertSame(['cg-1', 'student'], $person->groups);
        self::assertMatchesRegularExpression('/^entryUUID:[0-9a-f-]{36}$/', $person->stableId);
        self::assertSame('uid=ines.morel,ou=users,dc=peyra,dc=lan', $person->dn);
    }

    public function testTheSearchIsExactAndEscaped(): void
    {
        self::assertNull($this->directory->find('nobody'));
        self::assertNull($this->directory->find('ines*'));
        self::assertNull($this->directory->find('*'));
        self::assertNull($this->directory->find(''));
    }

    public function testChecksThePasswordByABindAsThePerson(): void
    {
        $person = $this->directory->find('prof.leroy');
        self::assertNotNull($person);

        self::assertTrue($this->directory->checkPassword($person, 'peyra-dev'));
        self::assertFalse($this->directory->checkPassword($person, 'wrong'));
        self::assertFalse($this->directory->checkPassword($person, ''));
    }

    public function testTheNameIsGivenNameAndSurnameBeforeAnythingElse(): void
    {
        $person = LdapDirectory::personOf(new Entry('uid=x,dc=t', [
            'uid' => ['x.y'],
            'cn' => ['x.y'],
            'givenName' => ['Xavier'],
            'sn' => ['Yon'],
            'uidNumber' => ['7'],
        ]), ['CG-1', 'teacher', 'cg-1'], 'uid', 'objectGUID');

        self::assertNotNull($person);
        self::assertSame('Xavier Yon', $person->displayName);
        self::assertSame('uidNumber:7', $person->stableId);
        self::assertSame(['cg-1', 'teacher'], $person->groups);

        $bare = LdapDirectory::personOf(new Entry('uid=z,dc=t', ['uid' => ['z'], 'uidNumber' => ['8']]), [], 'uid', 'objectGUID');
        self::assertSame('z', $bare?->displayName);
        self::assertNull(LdapDirectory::personOf(new Entry('uid=w,dc=t', ['uid' => ['w']]), [], 'uid', 'objectGUID'));
    }
}
