<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Identity\Directory;
use App\Service\Identity\DirectoryPerson;
use App\Service\Identity\DirectoryUnavailable;

/**
 * The directory of the test environment: the people of frankenphp/ldap/10-tree.ldif (password
 * `peyra-dev`), with stable ids of their own. A test may add a person, rename a login (same stable
 * id, another login) or take the directory down.
 */
final class InMemoryDirectory implements Directory
{
    public const string PASSWORD = 'peyra-dev';

    /** @var array<string, DirectoryPerson> by lower-case login */
    private array $people = [];

    private bool $down = false;

    public function __construct()
    {
        foreach ([
            ['prof.martin', 'Jean-Pierre Martin', 30001, ['teacher', 'cg-1', 'cg-2']],
            ['prof.durand', 'Sophie Durand', 30002, ['teacher', 'cg-1']],
            ['prof.leroy', 'Marc Leroy', 30003, ['teacher', 'sio-1']],
            ['lucas.bernard', 'Lucas Bernard', 40101, ['student', 'cg-1']],
            ['manon.chevalier', 'Manon Chevalier', 40102, ['student', 'cg-1']],
            ['theo.garnier', 'Théo Garnier', 40103, ['student', 'cg-1']],
            ['ines.morel', 'Inès Morel', 40104, ['student', 'cg-1']],
            ['adam.roussel', 'Adam Roussel', 40105, ['student', 'cg-1']],
            ['chloe.fabre', 'Chloé Fabre', 40106, ['student', 'cg-1']],
            ['yasmine.haddad', 'Yasmine Haddad', 40201, ['student', 'cg-2']],
            ['louis.perret', 'Louis Perret', 40202, ['student', 'cg-2']],
            ['camille.dufour', 'Camille Dufour', 40203, ['student', 'cg-2']],
            ['nathan.mercier', 'Nathan Mercier', 40204, ['student', 'cg-2']],
            ['lea.boyer', 'Léa Boyer', 40205, ['student', 'cg-2']],
            ['sacha.lambert', 'Sacha Lambert', 40206, ['student', 'cg-2']],
            ['enzo.petit', 'Enzo Petit', 40901, ['student', 'sio-1']],
            ['jules.martel', 'Jules Martel', 40903, ['student', 'cg-1', 'cg-2']],
        ] as [$login, $name, $number, $groups]) {
            $this->add($login, $name, $groups, 'entryUUID:test-'.$number);
        }
    }

    /** @param list<string> $groups */
    public function add(string $login, string $displayName, array $groups, string $stableId): DirectoryPerson
    {
        $person = new DirectoryPerson('uid='.$login.',ou=users,dc=peyra,dc=lan', $login, $stableId, $displayName, $login.'@peyra.test', $groups);
        $this->people[mb_strtolower($login)] = $person;

        return $person;
    }

    /** The directory renames a login: same person, same stable id, another uid. */
    public function rename(string $from, string $to): void
    {
        $person = $this->people[mb_strtolower($from)] ?? throw new \InvalidArgumentException($from.' is not in the directory.');
        unset($this->people[mb_strtolower($from)]);
        $this->add($to, $person->displayName, $person->groups, $person->stableId);
    }

    public function takeDown(): void
    {
        $this->down = true;
    }

    public function find(string $login): ?DirectoryPerson
    {
        if ($this->down) {
            throw new DirectoryUnavailable('The test directory is down.');
        }

        return $this->people[mb_strtolower(trim($login))] ?? null;
    }

    public function checkPassword(DirectoryPerson $person, #[\SensitiveParameter] string $password): bool
    {
        if ($this->down) {
            throw new DirectoryUnavailable('The test directory is down.');
        }

        return self::PASSWORD === $password;
    }
}
