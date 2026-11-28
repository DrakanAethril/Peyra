<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Dossier\Dossier;
use App\Service\Dossier\DossierLive;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubRegistry;
use Twig\Attribute\AsTwigFunction;

/**
 * The address a dossier's tab subscribes to (dossier_live_controller.js): the hub's public address
 * with the dossier's topic, and the cookie that lets this person read it, minted by the
 * `subscriber` hub (config/packages/mercure.yaml) and set on the answer. The page is drawn by
 * whoever DossierVoter let in, so the cookie grants what they already read. Live is a comfort: a
 * hub badly configured gives no address (null) and a warning, never a broken page.
 */
final readonly class DossierLiveExtension
{
    private const string HUB = 'subscriber';

    public function __construct(
        private HubRegistry $hubs,
        private Authorization $authorization,
        private RequestStack $requests,
        private LoggerInterface $logger,
    ) {
    }

    #[AsTwigFunction('dossier_live_url')]
    public function url(Dossier $dossier): ?string
    {
        $request = $this->requests->getMainRequest();
        $id = $dossier->getId();
        if (null === $request || null === $id) {
            return null;
        }
        $topic = DossierLive::topic($id);
        try {
            $this->authorization->setCookie($request, [$topic], hub: self::HUB);

            return $this->hubs->getHub(self::HUB)->getPublicUrl().'?topic='.rawurlencode($topic);
        } catch (\Throwable $exception) {
            $this->logger->warning('No live subscription for dossier {dossier}: {message}', ['dossier' => $id, 'message' => $exception->getMessage(), 'exception' => $exception]);

            return null;
        }
    }
}
