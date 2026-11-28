<?php

declare(strict_types=1);

namespace App\Service\Mission;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\Mission\Mission;
use App\Enum\Accounting\AccountingBehaviour;
use App\Enum\Accounting\AssistanceLevel;
use App\Enum\Audit\AuditAction;
use App\Enum\Mission\MissionModule;
use App\Service\Audit\AuditTrail;
use App\Service\Shared\Assistance\StudentAssistanceLevels;
use App\Service\Shared\Referential\Referential;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one door of a mission of the library (step 2.9a): created on a template, its tabs written
 * (Général, Tâches, Référentiel, Réglages), duplicated. Each gesture is checked by MissionRules - a
 * refusal is MissionRefused and writes nothing -, recorded in the audit trail (subject `mission`)
 * and flushed. Who may is MissionVoter's.
 */
final readonly class MissionSheet
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AuditTrail $auditTrail,
        private Referential $referential,
        private CurrentMission $current,
    ) {
    }

    /**
     * A new mission on a template: every module open, at the default level with « Laisser saisir »
     * (design-ux.md § 3.4: « Guidé » goes with « Proposer », the three other levels with « Laisser
     * saisir »).
     */
    public function create(Dossier $template, string $title, User $author, \DateTimeImmutable $at): Mission
    {
        if (!$template->isTemplate()) {
            throw new \LogicException('A mission is played on a template.');
        }
        $errors = array_intersect_key(MissionRules::general($title, '', [MissionModule::Documents->value]), ['title' => true]);
        if ([] !== $errors) {
            throw MissionRefused::of($errors);
        }
        $mission = new Mission($template, $author, $title, MissionModule::cases(), StudentAssistanceLevels::DEFAULT, AccountingBehaviour::LetEnter, $at);
        $this->entityManager->persist($mission);
        $this->entityManager->flush();
        $this->trail($mission, AuditAction::MissionCreated, $author, \sprintf('Mission « %s » créée sur %s', $title, $template->getLibraryTitle()));

        return $mission;
    }

    /** @param list<string> $modules MissionModule values */
    public function describe(Mission $mission, string $title, string $statement, array $modules, User $actor): void
    {
        $errors = MissionRules::general($title, $statement, $modules);
        if ([] !== $errors) {
            throw MissionRefused::of($errors);
        }
        $mission->describe($title, $statement, array_values(array_filter(array_map(MissionModule::tryFrom(...), $modules))));
        $this->changed($mission, $actor, 'général');
    }

    /** @param list<array{title: string, instruction: string}> $tasks in their order */
    public function setTasks(Mission $mission, array $tasks, User $actor): void
    {
        $errors = MissionRules::tasks($tasks);
        if ([] !== $errors) {
            throw MissionRefused::of($errors);
        }
        $mission->setTasks($tasks);
        $this->changed($mission, $actor, 'tâches');
    }

    /** @param list<string> $codes */
    public function aimAt(Mission $mission, array $codes, User $actor): void
    {
        $known = array_map(static fn ($activity): string => $activity->code, $this->referential->activities());
        $codes = array_values(array_unique($codes));
        $errors = MissionRules::activities($codes, $known);
        if ([] !== $errors) {
            throw MissionRefused::of($errors);
        }
        // Kept in the référentiel's order.
        $mission->aimAt(array_values(array_intersect($known, $codes)));
        $this->changed($mission, $actor, 'référentiel');
    }

    public function settle(Mission $mission, ?AssistanceLevel $level, ?AccountingBehaviour $behaviour, bool $guidedEntryOpen, User $actor): void
    {
        $errors = [];
        if (null === $level) {
            $errors['level'] = 'Choisissez le niveau d’assistance.';
        }
        if (null === $behaviour) {
            $errors['behaviour'] = 'Choisissez le comportement comptable des modules.';
        }
        if (null === $level || null === $behaviour) {
            throw MissionRefused::of($errors);
        }
        $mission->settle($level, $behaviour, $guidedEntryOpen);
        $this->changed($mission, $actor, 'réglages');
    }

    /** A copy of a mission the teacher reads, theirs, « (copie) », given to nobody. */
    public function duplicate(Mission $source, User $author, \DateTimeImmutable $at): Mission
    {
        $template = $source->getTemplate() ?? throw MissionRefused::of(['template' => 'Le dossier modèle de cette mission a été supprimé : elle ne se duplique plus.']);
        $title = mb_substr($source->getTitle().' (copie)', 0, MissionRules::TITLE_MAX);
        $copy = new Mission($template, $author, $title, $source->getModules(), $source->getAssistanceLevel(), $source->getBehaviour(), $at);
        $copy->describe($title, $source->getStatement(), $source->getModules());
        $copy->setTasks($source->getTasks());
        $copy->aimAt($source->getActivities());
        $copy->settle($source->getAssistanceLevel(), $source->getBehaviour(), $source->isGuidedEntryOpen());
        $this->entityManager->persist($copy);
        $this->entityManager->flush();
        $this->trail($copy, AuditAction::MissionCreated, $author, \sprintf('Mission « %s » créée en copie de la mission %d', $title, (int) $source->getId()));

        return $copy;
    }

    private function changed(Mission $mission, User $actor, string $tab): void
    {
        $this->trail($mission, AuditAction::MissionChanged, $actor, \sprintf('Mission « %s » modifiée (%s)', $mission->getTitle(), $tab));
        $this->current->reset();
    }

    private function trail(Mission $mission, AuditAction $action, User $actor, string $summary): void
    {
        $this->auditTrail->record($action, $actor, 'mission', (string) $mission->getId(), $summary);
        $this->entityManager->flush();
    }
}
