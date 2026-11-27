<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Repository\Dossier\DossierRepository;
use App\Security\Voter\DossierVoter;
use App\Service\Dossier\DossierContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Opens the dossier of a route's `{dossier}` (README of the plan, § 8): the dossier is loaded,
 * DossierVoter::VIEW decides - a refusal is the 404 of a page that is not there - and only then is
 * its context entered, so every DossierOwned read of the request is narrowed to it. The controller
 * receives the Dossier as its `dossier` argument.
 *
 * A student's visit is noted (« dernier passage »); when the date moved since their last one, the
 * request carries `dossier_date_moved` and the date chip flashes once (design-ux.md A18).
 *
 * After the firewall (priority 8): access_control has spoken, the person is known.
 */
final readonly class DossierRouteListener
{
    public const string DATE_MOVED = 'dossier_date_moved';

    /** A visit is noted at most every five minutes, unless the date moved since the last one. */
    private const int VISIT_EVERY_SECONDS = 300;

    public function __construct(
        private DossierRepository $dossiers,
        private Security $security,
        private DossierContext $context,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $params = $request->attributes->get('_route_params');
        if (!\is_array($params) || !\array_key_exists('dossier', $params)) {
            return;
        }
        $id = $params['dossier'];
        $dossier = \is_string($id) && 1 === preg_match('/^[1-9]\d{0,17}$/', $id) ? $this->dossiers->find((int) $id) : null;
        if (!$dossier instanceof Dossier || !$this->security->isGranted(DossierVoter::VIEW, $dossier)) {
            throw new NotFoundHttpException('No such dossier, or not yours to open.');
        }
        $this->context->enter($dossier);
        $request->attributes->set('dossier', $dossier);

        $user = $this->security->getUser();
        if ($request->isMethodSafe() && $user instanceof User && $user->isStudent()) {
            $this->noteVisit($dossier, $request->attributes);
        }
    }

    private function noteVisit(Dossier $dossier, ParameterBag $attributes): void
    {
        $now = $this->clock->now();
        $last = $dossier->getLastVisitedAt();
        $unseenMove = $dossier->isDateMoveUnseen();
        if ($unseenMove) {
            $attributes->set(self::DATE_MOVED, true);
        }
        if ($unseenMove || null === $last || $now->getTimestamp() - $last->getTimestamp() >= self::VISIT_EVERY_SECONDS) {
            $dossier->visited($now);
            $this->entityManager->flush();
        }
    }
}
