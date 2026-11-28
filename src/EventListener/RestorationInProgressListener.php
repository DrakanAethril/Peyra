<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Dossier\Dossier;
use App\Repository\Dossier\DossierRestorationRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * While a dossier goes back to a restore point, it shows its waiting page and nothing else
 * (design-ux.md § 4.9, step 3): a page of the dossier asked for is sent there; any other request
 * (a post, a grid's save, a search) is refused with a 409 - nothing is written into a dossier
 * whose rows are being replaced. Runs after DossierRouteListener has opened the dossier.
 */
final readonly class RestorationInProgressListener
{
    /** The routes a dossier answers while it is restored. */
    public const array WAITING_ROUTES = ['app_dossier_restoring', 'app_dossier_restoring_state'];

    public function __construct(private DossierRestorationRepository $restorations, private UrlGeneratorInterface $urls)
    {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 3)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $dossier = $request->attributes->get('dossier');
        if (!$dossier instanceof Dossier || \in_array($request->attributes->get('_route'), self::WAITING_ROUTES, true)) {
            return;
        }
        if (null === $this->restorations->findRunning($dossier)) {
            return;
        }
        $waiting = $this->urls->generate('app_dossier_restoring', ['dossier' => $dossier->getId()]);
        $wantsPage = $request->isMethod('GET') && !$request->headers->has('Turbo-Frame') && !$request->isXmlHttpRequest()
            && str_contains((string) $request->headers->get('Accept', 'text/html'), 'text/html');
        $event->setResponse($wantsPage
            ? new RedirectResponse($waiting)
            : new Response('Le dossier revient à un point de restauration : patientez un instant.', Response::HTTP_CONFLICT, ['Content-Type' => 'text/plain; charset=UTF-8']));
    }
}
