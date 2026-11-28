<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Accounting\FecImportJob;
use App\Entity\Dossier\Distribution;
use App\Entity\Dossier\Dossier;
use App\Service\Accounting\Fec\Import\FecImportLive;
use App\Service\Dossier\Distribution\DistributionLive;
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
        $id = $dossier->getId();

        return null === $id ? null : $this->subscribe(DossierLive::topic($id), 'dossier', $id);
    }

    /** A distribution's progress page (step 1.8c), drawn for its author only (DistributionVoter). */
    #[AsTwigFunction('distribution_live_url')]
    public function distributionUrl(Distribution $distribution): ?string
    {
        $id = $distribution->getId();

        return null === $id ? null : $this->subscribe(DistributionLive::topic($id), 'distribution', $id);
    }

    /** A FEC import's progress page (step 1.10b), drawn for its author only (FecImportVoter). */
    #[AsTwigFunction('fec_import_live_url')]
    public function fecImportUrl(FecImportJob $job): ?string
    {
        $id = $job->getId();

        return null === $id ? null : $this->subscribe(FecImportLive::topic($id), 'FEC import', $id);
    }

    private function subscribe(string $topic, string $what, int $id): ?string
    {
        $request = $this->requests->getMainRequest();
        if (null === $request) {
            return null;
        }
        try {
            $this->authorization->setCookie($request, [$topic], hub: self::HUB);

            return $this->hubs->getHub(self::HUB)->getPublicUrl().'?topic='.rawurlencode($topic);
        } catch (\Throwable $exception) {
            $this->logger->warning('No live subscription for {what} {id}: {message}', ['what' => $what, 'id' => $id, 'message' => $exception->getMessage(), 'exception' => $exception]);

            return null;
        }
    }
}
