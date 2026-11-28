<?php

declare(strict_types=1);

namespace App\Service\Mission\AnswerKey;

use App\Entity\Dossier\Dossier;
use App\Entity\Mission\Mission;
use App\Repository\Mission\MissionAnswerKeyRepository;

/**
 * What may be said of an answer key outside its own screen (step 2.9b), and nothing of its content:
 * whether a mission has one recorded (« Sans corrigé, la correction comparée ne proposera rien. »),
 * and which mission a teacher's answer key copy belongs to (its ribbon).
 */
final readonly class AnswerKeyStatus
{
    public function __construct(private MissionAnswerKeyRepository $answerKeys)
    {
    }

    public function isRecorded(Mission $mission): bool
    {
        return $this->answerKeys->findOneByMission($mission)?->isRecorded() ?? false;
    }

    public function missionOf(Dossier $dossier): ?Mission
    {
        return $this->answerKeys->findOneByDossier($dossier)?->getMission();
    }
}
