<?php

declare(strict_types=1);

namespace App\Service\Identity;

use App\Enum\Identity\EntryBasis;
use App\Repository\Identity\AuthorizedTeacherRepository;
use App\Repository\Identity\UserRepository;

/**
 * The reading of « Enseignants de la filière » (design-ux.md § 3.6): the teachers authorised by
 * name, the bootstrap list « d'office » (signed in or not yet), and, for the record, the teacher
 * accounts let in by their directory group. One line per person, by name.
 */
final readonly class FieldTeachers
{
    public function __construct(
        private AuthorizedTeacherRepository $authorizations,
        private UserRepository $users,
        private EntryPolicy $policy,
    ) {
    }

    /** @return list<FieldTeacherRow> */
    public function rows(): array
    {
        $rows = [];
        $seenIds = [];
        $seenLogins = [];
        foreach ($this->authorizations->findActive() as $authorization) {
            $account = $this->users->findOneByDirectoryId($authorization->getDirectoryId());
            $rows[] = new FieldTeacherRow(
                $account?->getDisplayName() ?? $authorization->getDisplayName(),
                $account?->getLogin() ?? $authorization->getLogin(),
                EntryBasis::NamedAuthorization,
                $account,
                $authorization,
                null,
            );
            $seenIds[$authorization->getDirectoryId()] = true;
            $seenLogins[mb_strtolower($account?->getLogin() ?? $authorization->getLogin())] = true;
        }

        foreach ($this->users->findTeachers() as $teacher) {
            if (isset($seenIds[$teacher->getDirectoryId()])) {
                continue;
            }
            $bootstrap = $this->policy->isBootstrapAdministrator($teacher->getLogin());
            $basis = $teacher->getEntryBasis();
            if (EntryBasis::NamedAuthorization === $basis && !$bootstrap) {
                // Authorised by name once, removed since: no longer let in.
                continue;
            }
            $groups = array_values(array_intersect($teacher->getDirectoryGroups(), $this->policy->teacherGroups()));
            $rows[] = new FieldTeacherRow(
                $teacher->getDisplayName(),
                $teacher->getLogin(),
                $bootstrap ? EntryBasis::BootstrapList : $basis,
                $teacher,
                null,
                [] === $groups ? null : implode(', ', $groups),
            );
            $seenLogins[mb_strtolower($teacher->getLogin())] = true;
        }

        foreach ($this->policy->adminLogins() as $login) {
            if (!isset($seenLogins[mb_strtolower($login)])) {
                $rows[] = new FieldTeacherRow(null, $login, EntryBasis::BootstrapList, null, null, null);
            }
        }

        usort($rows, static fn (FieldTeacherRow $a, FieldTeacherRow $b): int => strcoll(
            mb_strtolower($a->displayName ?? $a->login),
            mb_strtolower($b->displayName ?? $b->login),
        ));

        return $rows;
    }
}
