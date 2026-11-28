<?php

declare(strict_types=1);

namespace App\Controller\Mission;

use App\Entity\Mission\Mission;
use App\Repository\Mission\MissionRepository;
use App\Security\Voter\MissionVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** The mission of the address, its author's alone (MissionVoter: a 404 otherwise), and its form's token. */
trait MissionControllerTrait
{
    private function mission(int $id, MissionRepository $missions): Mission
    {
        $mission = $missions->find($id);
        if (null === $mission || !$this->isGranted(MissionVoter::EDIT, $mission)) {
            throw new NotFoundHttpException('No such mission.');
        }

        return $mission;
    }

    private function refuseBadToken(Mission $mission, Request $request): void
    {
        if (!$this->isCsrfTokenValid('mission-'.$mission->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Bad token.');
        }
    }
}
