<?php

declare(strict_types=1);

namespace App\Service\Mission;

use App\Entity\Dossier\Dossier;
use App\Entity\Mission\Mission;
use App\Entity\Mission\MissionAssignment;
use App\Enum\Mission\MissionModule;
use App\Repository\Mission\MissionAssignmentRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The mission a dossier is played in (step 2.9a): the latest given to the distribution that made it
 * (MissionAssignment), none for a template or a dossier no distribution made. What its settings
 * decide is read here: the modules it opens, the guided entry it opens; its level and its
 * behaviour are read by AssistanceLevels and AccountingBehaviours. Memoised per request.
 */
final class CurrentMission implements ResetInterface
{
    /** @var array<int, ?MissionAssignment> */
    private array $memo = [];

    public function __construct(private readonly MissionAssignmentRepository $assignments)
    {
    }

    public function of(Dossier $dossier): ?Mission
    {
        return $this->assignmentOf($dossier)?->getMission();
    }

    /** The giving of that mission to the dossier's distribution (its due date, step 2.9b's progress). */
    public function assignmentOf(Dossier $dossier): ?MissionAssignment
    {
        $id = $dossier->getId();
        if (null === $id || $dossier->isTemplate()) {
            return null;
        }
        if (!\array_key_exists($id, $this->memo)) {
            $this->memo[$id] = $this->assignments->findCurrentFor($dossier);
        }

        return $this->memo[$id];
    }

    /** Whether the module is open in the dossier: always without a mission. */
    public function opens(Dossier $dossier, MissionModule $module): bool
    {
        return $this->of($dossier)?->opens($module) ?? true;
    }

    /** Whether the mission opens the guided entry at A and 0 (« sauf réglage de la mission »). */
    public function opensGuidedEntry(Dossier $dossier): bool
    {
        return $this->of($dossier)?->isGuidedEntryOpen() ?? false;
    }

    public function reset(): void
    {
        $this->memo = [];
    }
}
