<?php

declare(strict_types=1);

namespace App\Controller\Identity;

use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Repository\Identity\SchoolClassRepository;
use App\Repository\Identity\SchoolYearRepository;
use App\Service\Identity\DirectoryGroupMappings;
use App\Service\Shared\PostValue;
use App\Service\Shared\QueryValue;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Paramètres › Administration › Correspondance avec l'annuaire (conception § 3 bis): for a year,
 * the class each student group of the directory places new accounts in. Administrators only.
 */
#[Route('/teacher/admin/directory')]
final class DirectoryMappingController extends AbstractController
{
    #[Route('', name: 'app_admin_directory', methods: ['GET'])]
    public function index(Request $request, SchoolYearRepository $years, SchoolClassRepository $classes, DirectoryGroupMappings $mappings): Response
    {
        $all = $years->findAllNewestFirst();
        $wanted = QueryValue::nullableInt($request, 'year');
        $year = null;
        foreach ($all as $candidate) {
            if ($candidate->getId() === $wanted) {
                $year = $candidate;
            }
        }
        $year ??= $years->findCurrent() ?? ($all[0] ?? null);

        return $this->render('admin/directory.html.twig', [
            'years' => $all,
            'year' => $year,
            'groups' => null === $year ? [] : $mappings->groups($year),
            'mapped' => null === $year ? [] : $mappings->classByGroup($year),
            'year_classes' => null === $year ? [] : $classes->findByYear($year),
        ]);
    }

    #[Route('/{id}', name: 'app_admin_directory_save', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function save(#[MapEntity] SchoolYear $year, Request $request, SchoolClassRepository $classes, DirectoryGroupMappings $mappings, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        if (!$this->isCsrfTokenValid('admin-directory-'.$year->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Invalid CSRF token.');
        }
        $byId = [];
        foreach ($classes->findByYear($year) as $class) {
            $byId[(int) $class->getId()] = $class;
        }
        $posted = PostValue::stringsByKey($request, 'group');
        $choices = [];
        foreach ($mappings->groups($year) as $group) {
            $value = $posted[$group] ?? '';
            $choices[$group] = ctype_digit($value) ? ($byId[(int) $value] ?? null) : null;
        }
        $changed = $mappings->save($year, $choices, $me, $clock->now());
        $this->addFlash('status', 0 === $changed ? 'Aucun changement.' : \sprintf('Correspondance de %s enregistrée (%d %s).', $year->getLabel(), $changed, $changed > 1 ? 'groupes' : 'groupe'));

        return $this->redirectToRoute('app_admin_directory', ['year' => $year->getId()], Response::HTTP_SEE_OTHER);
    }
}
