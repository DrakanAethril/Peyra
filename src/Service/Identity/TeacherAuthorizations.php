<?php

declare(strict_types=1);

namespace App\Service\Identity;

use App\Entity\Identity\AuthorizedTeacher;
use App\Entity\Identity\User;
use App\Enum\Identity\AdministrationAction;
use App\Repository\Identity\AuthorizedTeacherRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Enseignants de la filière » (design-ux.md § 3.6): an administrator lets a teacher in by name,
 * checked against the directory when added, and removes them. Every gesture is journaled.
 */
final readonly class TeacherAuthorizations
{
    public function __construct(
        private Directory $directory,
        private AuthorizedTeacherRepository $authorizations,
        private AdministrationJournal $journal,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Adds the teacher whose directory login this is.
     *
     * @return string|null the refusal to show under the field, null once added
     *
     * @throws DirectoryUnavailable
     */
    public function add(string $login, User $by, \DateTimeImmutable $at): ?string
    {
        $login = trim($login);
        if ('' === $login) {
            return 'Indiquez l’identifiant de l’annuaire.';
        }
        $person = $this->directory->find($login);
        if (null === $person) {
            return 'Identifiant inconnu de l’annuaire.';
        }
        if (!\in_array(EntryRule::TEACHER_GROUP, $person->groups, true)) {
            return \sprintf('%s n’est pas enseignant dans l’annuaire.', $person->displayName);
        }
        if ($this->authorizations->isAuthorized($person->stableId)) {
            return \sprintf('%s figure déjà dans la liste.', $person->displayName);
        }

        $this->entityManager->persist(new AuthorizedTeacher($person->stableId, $person->login, $person->displayName, $by, $at));
        $this->journal->record($at, $by, AdministrationAction::TeacherAuthorized, $person->login, $person->displayName);
        $this->entityManager->flush();

        return null;
    }

    public function revoke(AuthorizedTeacher $authorization, User $by, \DateTimeImmutable $at): void
    {
        if (!$authorization->isActive()) {
            return;
        }
        $authorization->revoke($by, $at);
        $this->journal->record($at, $by, AdministrationAction::TeacherAuthorizationRevoked, $authorization->getLogin(), $authorization->getDisplayName());
        $this->entityManager->flush();
    }
}
