<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Dossier\Dossier;
use App\Entity\Mission\Mission;
use App\Enum\Mission\MissionModule;
use App\Service\Mission\AnswerKey\AnswerKeyStatus;
use App\Service\Mission\MissionModuleAccess;
use App\Service\Mission\Play\MissionPlay;
use App\Service\Mission\Play\MissionPlayView;
use Symfony\Component\Clock\ClockInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * `mission_opens(dossier, 'sales')`: whether the rail draws a module for the person (step 2.9a,
 * MissionModuleAccess); `mission_play(dossier)`: the mission banner and panel of the dossier, null
 * without a mission (step 2.9b, MissionPlay); `answer_key_mission(dossier)`: the mission a teacher's
 * answer key copy belongs to (its ribbon).
 */
final readonly class MissionExtension
{
    public function __construct(private MissionModuleAccess $access, private MissionPlay $play, private AnswerKeyStatus $answerKeys, private ClockInterface $clock)
    {
    }

    #[AsTwigFunction('mission_opens')]
    public function opens(Dossier $dossier, string $module): bool
    {
        return $this->access->allows($dossier, MissionModule::from($module));
    }

    #[AsTwigFunction('mission_play')]
    public function play(Dossier $dossier): ?MissionPlayView
    {
        return $this->play->view($dossier, $this->clock->now());
    }

    #[AsTwigFunction('answer_key_mission')]
    public function answerKeyMission(Dossier $dossier): ?Mission
    {
        return $this->answerKeys->missionOf($dossier);
    }
}
