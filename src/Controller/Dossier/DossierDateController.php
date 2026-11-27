<?php

declare(strict_types=1);

namespace App\Controller\Dossier;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Security\Voter\DossierVoter;
use App\Service\Dossier\DossierDates;
use App\Service\Shared\DateValue;
use App\Service\Shared\PostValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * « Avancer la date », from the date chip's panel (design-ui.md § 4.2): a teacher of the dossier
 * moves its date (DossierVoter::SET_DATE; a student gets the 404 of a page that is not there), and
 * comes back to the screen they were on.
 */
final class DossierDateController extends AbstractController
{
    #[Route('/d/{dossier}/date', name: 'app_dossier_date', requirements: ['dossier' => '\d+'], methods: ['POST'])]
    public function move(Dossier $dossier, Request $request, DossierDates $dates, EntityManagerInterface $entityManager, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        if (!$this->isGranted(DossierVoter::SET_DATE, $dossier) || !$this->isCsrfTokenValid('dossier-date-'.$dossier->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not yours to move.');
        }
        $to = DateValue::parse(PostValue::trimmed($request, 'date'));
        if (null === $to) {
            $this->addFlash('status', 'Date du dossier inchangée : saisissez un jour du calendrier (JJ/MM/AAAA).');
        } elseif (null === $dates->move($dossier, $to, $me, $clock->now())) {
            $this->addFlash('status', 'Date du dossier inchangée : c’est déjà le '.$to->format('d/m/Y').'.');
        } else {
            $entityManager->flush();
            $this->addFlash('status', 'Date du dossier : '.$to->format('d/m/Y').'.');
        }

        return $this->redirect(self::backTo($dossier, PostValue::trimmed($request, 'return')), Response::HTTP_SEE_OTHER);
    }

    /** The screen of this dossier the panel was opened on; its dashboard for anything else. */
    private static function backTo(Dossier $dossier, string $path): string
    {
        $prefix = '/d/'.$dossier->getId();

        return 1 === preg_match('#^'.preg_quote($prefix, '#').'(/[A-Za-z0-9/_-]*)?(\?[^\s]*)?$#', $path) ? $path : $prefix;
    }
}
