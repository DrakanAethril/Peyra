<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Dossier\Dossier;
use App\Service\Dossier\Search\RecentScreens;
use App\Service\Dossier\Search\SearchScreens;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Notes a screen of the global search's catalogue (SearchScreens) among the person's recent ones
 * when it is shown: a page read whole (GET, 200, HTML, not a Turbo Frame) of a dossier.
 */
final readonly class RecentScreensListener
{
    public function __construct(private RecentScreens $recent)
    {
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();
        if (!$event->isMainRequest() || !$request->isMethod('GET') || Response::HTTP_OK !== $response->getStatusCode() || $request->headers->has('Turbo-Frame')) {
            return;
        }
        if (!str_starts_with((string) $response->headers->get('Content-Type', 'text/html'), 'text/html')) {
            return;
        }
        $dossier = $request->attributes->get('dossier');
        $route = $request->attributes->get('_route');
        if (!$dossier instanceof Dossier || !\is_string($route)) {
            return;
        }
        $journal = $request->query->get('journal');
        $key = SearchScreens::keyOf($route, \is_string($journal) ? $journal : '');
        if (null !== $key) {
            $this->recent->record((int) $dossier->getId(), $key);
        }
    }
}
