<?php

declare(strict_types=1);

namespace App\Service\Mission;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Mission\MissionModule;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Whether the person may reach a module of a dossier (step 2.9a; design-ux.md § 2.4): a teacher
 * reaches every module, even those the mission closes; anybody else those the dossier's mission opens
 * (CurrentMission), and every module without a mission. The rail, the search and the addresses
 * (MissionModulesListener) ask it.
 */
final readonly class MissionModuleAccess
{
    public function __construct(
        private Security $security,
        private CurrentMission $missions,
    ) {
    }

    public function allows(Dossier $dossier, ?MissionModule $module): bool
    {
        if (null === $module) {
            return true;
        }
        $me = $this->security->getUser();
        if ($me instanceof User && $me->isTeacher()) {
            return true;
        }

        return $this->missions->opens($dossier, $module);
    }

    /** The same question for an address of the dossier (« /d/12/sales/customers »). */
    public function allowsPath(Dossier $dossier, string $path): bool
    {
        return $this->allows($dossier, MissionModule::ofPath($path));
    }
}
