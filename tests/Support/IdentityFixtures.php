<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Identity\SchoolClass;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Enum\Identity\AccountKind;
use App\Enum\Identity\ClassLevel;
use App\Enum\Identity\EntryBasis;

/**
 * Entities built in memory for the unit tests, with the ids a database would give them (the rules
 * group by id). In a file of its own: ParaTest hands out files, not classes.
 */
final class IdentityFixtures
{
    public static function year(int $id, int $startYear, bool $current = false): SchoolYear
    {
        $year = new SchoolYear(new \DateTimeImmutable($startYear.'-09-01'), new \DateTimeImmutable(($startYear + 1).'-08-31'), new \DateTimeImmutable('2026-01-01'));
        if ($current) {
            $year->markCurrent();
        }

        return self::withId($year, $id);
    }

    public static function schoolClass(int $id, SchoolYear $year, string $name, ClassLevel $level): SchoolClass
    {
        return self::withId(new SchoolClass($year, $name, $level, new \DateTimeImmutable('2026-01-01')), $id);
    }

    public static function student(int $id, string $name): User
    {
        return self::withId(new User('uidNumber:'.$id, 'student'.$id, $name, AccountKind::Student, EntryBasis::StudentGroup, ['student', 'cg-1'], new \DateTimeImmutable('2026-01-01')), $id);
    }

    public static function teacher(int $id, string $name): User
    {
        return self::withId(new User('uidNumber:'.$id, 'teacher'.$id, $name, AccountKind::Teacher, EntryBasis::TeacherGroup, ['teacher', 'cg-1'], new \DateTimeImmutable('2026-01-01')), $id);
    }

    /**
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private static function withId(object $entity, int $id): object
    {
        new \ReflectionProperty($entity, 'id')->setValue($entity, $id);

        return $entity;
    }
}
