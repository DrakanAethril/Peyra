<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Dossier\Dossier;
use App\Service\Mission\MissionModuleAccess;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A module the dossier's mission closes does not exist for the student (step 2.9a): every address of
 * it answers a 404, after DossierRouteListener (priority 4) has loaded the dossier and entered it.
 */
final readonly class MissionModulesListener
{
    public function __construct(private MissionModuleAccess $access)
    {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 3)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $dossier = $request->attributes->get('dossier');
        if ($event->isMainRequest() && $dossier instanceof Dossier && !$this->access->allowsPath($dossier, $request->getPathInfo())) {
            throw new NotFoundHttpException('A module the mission closes.');
        }
    }
}
