<?php

declare(strict_types=1);

namespace App\Service\Identity;

use App\Enum\Identity\AccountKind;
use App\Enum\Identity\EntryBasis;
use App\Enum\Identity\EntryRefusal;

/**
 * The entry rule (README of the plan, § 9; conception § 3), a pure function on primitives:
 *
 * - a teacher (directory group `teacher`) enters as a member of a group of the teacher list, or as
 *   a login of the bootstrap list, or as a teacher an administrator authorised by name;
 * - a student (directory group `student`) enters as a member of a group of the student list;
 * - anybody else is refused, Formatool's administrators included.
 *
 * The teacher test comes first: an account that is both is a teacher if the rule lets them in as
 * one. A teacher who fails every teacher test but is a CG student still enters, as a student.
 */
final class EntryRule
{
    public const string TEACHER_GROUP = 'teacher';
    public const string STUDENT_GROUP = 'student';

    /**
     * @param list<string> $groups        the person's directory groups (cn)
     * @param list<string> $studentGroups PEYRA_STUDENT_GROUPS
     * @param list<string> $teacherGroups PEYRA_TEACHER_GROUPS
     * @param list<string> $adminLogins   PEYRA_ADMIN_LOGINS
     */
    public static function decide(string $login, array $groups, array $studentGroups, array $teacherGroups, array $adminLogins, bool $authorizedByName): EntryDecision
    {
        $groups = array_map(mb_strtolower(...), $groups);
        $isTeacher = \in_array(self::TEACHER_GROUP, $groups, true);
        $isStudent = \in_array(self::STUDENT_GROUP, $groups, true);

        if ($isTeacher) {
            if (self::meets($groups, $teacherGroups)) {
                return EntryDecision::admit(AccountKind::Teacher, EntryBasis::TeacherGroup);
            }
            if (self::isBootstrapAdministrator($login, $adminLogins)) {
                return EntryDecision::admit(AccountKind::Teacher, EntryBasis::BootstrapList);
            }
            if ($authorizedByName) {
                return EntryDecision::admit(AccountKind::Teacher, EntryBasis::NamedAuthorization);
            }
        }
        if ($isStudent && self::meets($groups, $studentGroups)) {
            return EntryDecision::admit(AccountKind::Student, EntryBasis::StudentGroup);
        }

        return EntryDecision::refuse($isTeacher ? EntryRefusal::TeacherNotAuthorized : EntryRefusal::NotInProgramme);
    }

    /**
     * Whether the login is in the bootstrap list, compared without case (directories match logins
     * so).
     *
     * @param list<string> $adminLogins
     */
    public static function isBootstrapAdministrator(string $login, array $adminLogins): bool
    {
        return \in_array(mb_strtolower($login), array_map(mb_strtolower(...), $adminLogins), true);
    }

    /**
     * A comma-separated list of the environment, trimmed, empty items dropped.
     *
     * @return list<string>
     */
    public static function listOf(string $value): array
    {
        return array_values(array_filter(array_map(trim(...), explode(',', $value)), static fn (string $item): bool => '' !== $item));
    }

    /**
     * @param list<string> $groups
     * @param list<string> $accepted
     */
    private static function meets(array $groups, array $accepted): bool
    {
        return [] !== array_intersect($groups, array_map(mb_strtolower(...), $accepted));
    }
}
