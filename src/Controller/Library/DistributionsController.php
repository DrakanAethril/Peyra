<?php

declare(strict_types=1);

namespace App\Controller\Library;

use App\Entity\Dossier\Distribution;
use App\Entity\Dossier\DistributionCopy;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Repository\Dossier\DistributionCopyRepository;
use App\Repository\Dossier\DistributionRepository;
use App\Repository\Dossier\RestorePointRepository;
use App\Repository\Identity\UserRepository;
use App\Security\Voter\DistributionVoter;
use App\Security\Voter\DossierVoter;
use App\Security\Voter\RestorePointVoter;
use App\Service\Dossier\ClassDossierRow;
use App\Service\Dossier\ClassDossiers;
use App\Service\Dossier\Distribution\DistributionProgress;
use App\Service\Dossier\Distribution\DistributionRefused;
use App\Service\Dossier\Distribution\Distributions;
use App\Service\Dossier\DossierLive;
use App\Service\Dossier\Restore\RestoreBatches;
use App\Service\Dossier\Restore\RestorePointRules;
use App\Service\Shared\DateValue;
use App\Service\Shared\PostValue;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Bibliothèque › Distribution (design-ux.md §§ 2.6 and 4.2): the teacher's distributions, newest
 * first, with how far each stands; a distribution's progress page - one line per dossier (en
 * attente, en copie, prêt, en échec), the global bar, redrawn live and asked again while copies
 * are pending -, « Relancer » on a failed line, the date of all its dossiers in one gesture, and
 * « Remettre la distribution à l'état de départ » (its ready dossiers, through the récapitulatif of
 * Classe › Dossiers), « Retirer la distribution » (a récapitulatif, then its title typed). All its author's
 * (DistributionVoter): anybody else gets a 404.
 */
#[Route('/teacher/library/distributions')]
final class DistributionsController extends AbstractController
{
    #[Route('', name: 'app_library_distributions', methods: ['GET'])]
    public function index(DistributionRepository $distributions, DistributionCopyRepository $copies, #[CurrentUser] User $me): Response
    {
        $mine = $distributions->findByAuthor($me);
        $counts = $copies->countByState($mine);

        return $this->render('library/distributions.html.twig', [
            'distribution_rows' => array_map(static fn (Distribution $distribution): array => [
                'distribution' => $distribution,
                'progress' => DistributionProgress::of($counts[(int) $distribution->getId()] ?? []),
            ], $mine),
        ]);
    }

    #[Route('/{id}', name: 'app_library_distribution', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(#[MapEntity] Distribution $distribution, DistributionCopyRepository $copies, UserRepository $users): Response
    {
        $this->guard($distribution);
        $lines = $copies->findByDistribution($distribution);
        $progress = DistributionProgress::of($copies->countByState([$distribution])[(int) $distribution->getId()] ?? []);

        return $this->render('library/distribution.html.twig', [
            'distribution' => $distribution,
            'copies' => $lines,
            'members' => self::members($lines, $users),
            'progress' => $progress,
            'dossier_date' => self::dossierDate($lines) ?? $distribution->getStartDate(),
        ]);
    }

    #[Route('/{id}/copies/{copy}/retry', name: 'app_library_distribution_retry', requirements: ['id' => '\d+', 'copy' => '\d+'], methods: ['POST'])]
    public function retry(#[MapEntity] Distribution $distribution, #[MapEntity(id: 'copy')] DistributionCopy $copy, Request $request, Distributions $distributions, #[CurrentUser] User $me): Response
    {
        $this->guard($distribution);
        if ($copy->getDistribution() !== $distribution || !$this->isCsrfTokenValid('distribution-'.$distribution->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not a copy of this distribution.');
        }
        try {
            $distributions->retry($copy, $me);
            $this->addFlash('status', \sprintf('Copie du dossier de %s relancée.', Distributions::holderOf($copy)));
        } catch (DistributionRefused $refused) {
            $this->addFlash('status', $refused->getMessage());
        }

        return $this->redirectToRoute('app_library_distribution', ['id' => $distribution->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/date', name: 'app_library_distribution_date', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function date(#[MapEntity] Distribution $distribution, Request $request, Distributions $distributions, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        $this->guard($distribution);
        if (!$this->isCsrfTokenValid('distribution-'.$distribution->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('A stale form.');
        }
        $to = DateValue::parse(PostValue::trimmed($request, 'date'));
        if (null === $to) {
            $this->addFlash('status', 'Dates inchangées : saisissez un jour du calendrier (JJ/MM/AAAA).');
        } else {
            $counts = $distributions->moveDate($distribution, $to, $me, $clock->now(), fn (Dossier $dossier): bool => $this->isGranted(DossierVoter::SET_DATE, $dossier));
            $this->addFlash('status', self::dateSentence($to, $counts));
        }

        return $this->redirectToRoute('app_library_distribution', ['id' => $distribution->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/withdraw', name: 'app_library_distribution_withdraw', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function withdraw(#[MapEntity] Distribution $distribution, Request $request, Distributions $distributions, DistributionCopyRepository $copies, RestorePointRepository $points, #[CurrentUser] User $me): Response
    {
        $this->guard($distribution);
        $error = null;
        $typed = '';
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('distribution-withdraw-'.$distribution->getId(), $request->request->getString('_token'))) {
                throw new NotFoundHttpException('A stale form.');
            }
            $typed = PostValue::trimmed($request, 'confirmTitle');
            $title = $distribution->getTitle();
            $label = $distribution->getSchoolClass()->getLabel();
            $error = $distributions->withdraw($distribution, $typed, $me);
            if (null === $error) {
                $this->addFlash('status', \sprintf('Distribution de « %s » en %s retirée.', $title, $label));

                return $this->redirectToRoute('app_library_distributions', [], Response::HTTP_SEE_OTHER);
            }
        }
        $dossiers = array_values(array_filter(array_map(static fn (DistributionCopy $copy): ?Dossier => $copy->getDossier(), $copies->findByDistribution($distribution))));

        return $this->render('library/distribution_withdraw.html.twig', [
            'distribution' => $distribution,
            'dossiers' => \count($dossiers),
            'points' => array_sum(array_map(static fn (Dossier $dossier): int => $points->countByDossier($dossier), $dossiers)),
            'error' => $error,
            'typed' => $typed,
        ], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /** « Remettre la distribution à l'état de départ »: the récapitulatif of its ready dossiers. */
    #[Route('/{id}/reset', name: 'app_library_distribution_reset', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function resetSummary(#[MapEntity] Distribution $distribution, DistributionCopyRepository $copies, ClassDossiers $dossiers, RestoreBatches $batches): Response
    {
        $this->guard($distribution);
        $rows = $this->resettable($distribution, $copies, $dossiers);
        if ([] === $rows) {
            $this->addFlash('status', 'Aucun dossier de cette distribution à remettre à l’état de départ : aucun n’est prêt, ou vous n’enseignez pas dans la classe de leurs étudiants cette année.');

            return $this->redirectToRoute('app_library_distribution', ['id' => $distribution->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('library/distribution_reset.html.twig', [
            'distribution' => $distribution,
            'lines' => $batches->summaries($rows),
            'max_points' => RestorePointRules::LIMIT,
        ]);
    }

    #[Route('/{id}/reset', name: 'app_library_distribution_reset_start', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reset(#[MapEntity] Distribution $distribution, Request $request, DistributionCopyRepository $copies, ClassDossiers $dossiers, RestoreBatches $batches, DossierLive $live, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        $this->guard($distribution);
        if (!$this->isCsrfTokenValid('distribution-reset-'.$distribution->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('A stale form.');
        }
        $ids = PostValue::intList($request, 'dossiers');
        $rows = array_values(array_filter($this->resettable($distribution, $copies, $dossiers), static fn (ClassDossierRow $row): bool => \in_array((int) $row->dossier->getId(), $ids, true)));
        $outcome = $batches->resetToStart($rows, $request->request->getBoolean('keep'), $me, $clock->now());
        foreach ($rows as $row) {
            $live->announce($row->dossier, DossierLive::RESTORING);
        }
        $this->addFlash('status', $outcome->sentence('dossier remis à l’état de départ', 'dossiers remis à l’état de départ'));

        return $this->redirectToRoute('app_library_distribution', ['id' => $distribution->getId()], Response::HTTP_SEE_OTHER);
    }

    /**
     * The distribution's ready dossiers the person may go back in (a teacher of their students'
     * class this year: the distributor alone only reads them).
     *
     * @return list<ClassDossierRow>
     */
    private function resettable(Distribution $distribution, DistributionCopyRepository $copies, ClassDossiers $dossiers): array
    {
        $made = array_values(array_filter(array_map(static fn (DistributionCopy $copy): ?Dossier => $copy->getDossier(), $copies->findByDistribution($distribution))));

        return array_values(array_filter($dossiers->rowsOf($made), fn (ClassDossierRow $row): bool => $this->isGranted(RestorePointVoter::POSE, $row->dossier)
            && (null === $row->start || $this->isGranted(RestorePointVoter::RETURN, $row->start))));
    }

    private function guard(Distribution $distribution): void
    {
        if (!$this->isGranted(DistributionVoter::MANAGE, $distribution)) {
            throw new NotFoundHttpException('Not your distribution.');
        }
    }

    /**
     * The members of the groups' dossiers, by student id (one query for the page).
     *
     * @param list<DistributionCopy> $copies
     *
     * @return array<int, User>
     */
    private static function members(array $copies, UserRepository $users): array
    {
        $ids = [];
        foreach ($copies as $copy) {
            if (null === $copy->getStudent()) {
                array_push($ids, ...$copy->studentIds());
            }
        }
        $found = [];
        foreach ([] === $ids ? [] : $users->findBy(['id' => array_values(array_unique($ids))]) as $user) {
            $found[(int) $user->getId()] = $user;
        }

        return $found;
    }

    /** @param list<DistributionCopy> $copies the date the field offers: the first ready dossier's */
    private static function dossierDate(array $copies): ?\DateTimeImmutable
    {
        foreach ($copies as $copy) {
            if (null !== $copy->getDossier()) {
                return $copy->getDossier()->getDossierDate();
            }
        }

        return null;
    }

    /** @param array{moved: int, unchanged: int, refused: int} $counts */
    private static function dateSentence(\DateTimeImmutable $to, array $counts): string
    {
        $parts = [\sprintf('%d %s au %s', $counts['moved'], $counts['moved'] > 1 ? 'dossiers datés' : 'dossier daté', $to->format('d/m/Y'))];
        if ($counts['unchanged'] > 0) {
            $parts[] = \sprintf('%d déjà à cette date', $counts['unchanged']);
        }
        if ($counts['refused'] > 0) {
            $parts[] = \sprintf('%d non %s : vous n’enseignez pas dans la classe de %s cette année', $counts['refused'], $counts['refused'] > 1 ? 'changés' : 'changé', $counts['refused'] > 1 ? 'leurs étudiants' : 'son étudiant');
        }

        return implode(' · ', $parts).'.';
    }
}
