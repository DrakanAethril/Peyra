<?php

declare(strict_types=1);

namespace App\Service\Identity;

use App\Repository\Identity\AuthorizedTeacherRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The entry rule with its inputs: the three lists of the environment and the named authorisations
 * of the database. Asked at sign-in, and again at each request of an open session (UserProvider):
 * a teacher removed from « Enseignants de la filière » is signed out at their next click.
 */
final readonly class EntryPolicy
{
    /** @var list<string> */
    private array $studentGroups;
    /** @var list<string> */
    private array $teacherGroups;
    /** @var list<string> */
    private array $adminLogins;

    public function __construct(
        private AuthorizedTeacherRepository $authorizations,
        #[Autowire(env: 'PEYRA_STUDENT_GROUPS')] string $studentGroups,
        #[Autowire(env: 'PEYRA_TEACHER_GROUPS')] string $teacherGroups,
        #[Autowire(env: 'PEYRA_ADMIN_LOGINS')] string $adminLogins,
    ) {
        $this->studentGroups = EntryRule::listOf($studentGroups);
        $this->teacherGroups = EntryRule::listOf($teacherGroups);
        $this->adminLogins = EntryRule::listOf($adminLogins);
    }

    /** @param list<string> $groups */
    public function decide(string $login, string $directoryId, array $groups): EntryDecision
    {
        return EntryRule::decide(
            $login,
            $groups,
            $this->studentGroups,
            $this->teacherGroups,
            $this->adminLogins,
            $this->authorizations->isAuthorized($directoryId),
        );
    }

    public function isBootstrapAdministrator(string $login): bool
    {
        return EntryRule::isBootstrapAdministrator($login, $this->adminLogins);
    }

    /** @return list<string> */
    public function adminLogins(): array
    {
        return $this->adminLogins;
    }

    /** @return list<string> */
    public function teacherGroups(): array
    {
        return $this->teacherGroups;
    }
}
