<?php

declare(strict_types=1);

namespace App\Controller\Closing;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\Exercise;
use App\Repository\Dossier\ExerciseRepository;
use App\Service\Accounting\Fec\FecExport;
use App\Service\Shared\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Immobilisations et clôture › Clôture › FEC (design-ux.md § 3.2, « Archivage et FEC », gabarit R;
 * lot 1, step 1.9a): the FEC of an exercise - the exercise of the dossier's date by default -, its
 * conformity check and its download. A reading: whoever opens the dossier, every profile.
 */
#[Route('/d/{dossier}/closing/fec', requirements: ['dossier' => '\d+'])]
final class FecController extends AbstractController
{
    #[Route('', name: 'app_closing_fec', methods: ['GET'])]
    public function index(Dossier $dossier, Request $request, ExerciseRepository $exercises, FecExport $export): Response
    {
        $all = $exercises->findByDossier($dossier);
        $exercise = self::exercise($dossier, $request, $exercises, $all);

        return $this->render('closing/fec.html.twig', [
            'dossier' => $dossier,
            'exercises' => $all,
            'exercise' => $exercise,
            'fec' => null === $exercise ? null : $export->of($dossier, $exercise),
        ]);
    }

    #[Route('/download', name: 'app_closing_fec_download', methods: ['GET'])]
    public function download(Dossier $dossier, Request $request, ExerciseRepository $exercises, FecExport $export): Response
    {
        $exercise = self::exercise($dossier, $request, $exercises, $exercises->findByDossier($dossier)) ?? throw new NotFoundHttpException('No exercise.');
        $fec = $export->of($dossier, $exercise);

        return new Response($fec->content, Response::HTTP_OK, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $fec->name),
        ]);
    }

    /**
     * The exercise named by `exercise` (its code), else the one of the dossier's date, else the first.
     *
     * @param list<Exercise> $all
     */
    private static function exercise(Dossier $dossier, Request $request, ExerciseRepository $exercises, array $all): ?Exercise
    {
        $code = QueryValue::trimmed($request, 'exercise');
        foreach ($all as $one) {
            if ($one->getCode() === $code) {
                return $one;
            }
        }

        return $exercises->findAt($dossier, $dossier->getDossierDate()) ?? ($all[0] ?? null);
    }
}
