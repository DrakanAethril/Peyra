<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Dossier\Dossier;
use App\Enum\Mission\MissionModule;
use App\Service\Mission\MissionModuleAccess;
use Twig\Attribute\AsTwigFunction;

/** `mission_opens(dossier, 'sales')`: whether the rail draws a module for the person (step 2.9a, MissionModuleAccess). */
final readonly class MissionExtension
{
    public function __construct(private MissionModuleAccess $access)
    {
    }

    #[AsTwigFunction('mission_opens')]
    public function opens(Dossier $dossier, string $module): bool
    {
        return $this->access->allows($dossier, MissionModule::from($module));
    }
}
