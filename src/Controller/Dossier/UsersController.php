<?php

declare(strict_types=1);

namespace App\Controller\Dossier;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Dossier\AccessLevel;
use App\Enum\Dossier\DossierArea;
use App\Enum\Dossier\DossierKind;
use App\Enum\Dossier\DossierProfile;
use App\Security\Voter\DossierVoter;
use App\Service\Dossier\Rights\DossierProfiles;
use App\Service\Dossier\Rights\DossierRights;
use App\Service\Dossier\Rights\DossierUsers;
use App\Service\Dossier\Rights\ProfileMatrix;
use App\Service\Shared\PostValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Système d'information › Utilisateurs et droits (design-ux.md § 3.2, gabarit L puis F; lot 1,
 * step 1.7): who enters the dossier and how far, and the matrix of the profiles, area by area. In a
 * group's dossier a teacher of the class gives each member a profile (F10, DossierVoter::SET_DATE:
 * a teacher's gesture), every change in the dossier's trail; everybody else reads.
 */
#[Route('/d/{dossier}/users', requirements: ['dossier' => '\d+'])]
final class UsersController extends AbstractController
{
    #[Route('', name: 'app_dossier_users', methods: ['GET'])]
    public function index(Dossier $dossier, DossierUsers $users, DossierRights $rights, #[CurrentUser] User $me): Response
    {
        return $this->render('dossier/users.html.twig', [
            'dossier' => $dossier,
            'user_rows' => $users->of($dossier),
            'group' => DossierKind::Group === $dossier->getKind(),
            'can_assign' => DossierKind::Group === $dossier->getKind() && $this->isGranted(DossierVoter::SET_DATE, $dossier),
            'my_profile' => $rights->profileOf($dossier, $me),
            'profiles' => DossierProfile::cases(),
            'matrix' => array_map(static fn (DossierArea $area): array => [
                'area' => $area,
                'levels' => array_map(static fn (DossierProfile $profile): AccessLevel => ProfileMatrix::level($profile, $area), DossierProfile::cases()),
            ], DossierArea::cases()),
        ]);
    }

    #[Route('', name: 'app_dossier_users_save', methods: ['POST'])]
    public function save(Dossier $dossier, Request $request, DossierProfiles $profiles, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        if (DossierKind::Group !== $dossier->getKind() || !$this->isGranted(DossierVoter::SET_DATE, $dossier) || !$this->isCsrfTokenValid('dossier-users-'.$dossier->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not yours to change.');
        }
        $asked = [];
        foreach (PostValue::stringsById($request, 'profile') as $member => $value) {
            $asked[$member] = DossierProfile::tryFrom($value);
        }
        $changed = $profiles->assign($dossier, $asked, $me);
        $entityManager->flush();
        $this->addFlash('status', match ($changed) {
            0 => 'Rien à enregistrer : les profils sont inchangés.',
            1 => 'Profil enregistré.',
            default => \sprintf('%d profils enregistrés.', $changed),
        });

        return $this->redirectToRoute('app_dossier_users', ['dossier' => $dossier->getId()], Response::HTTP_SEE_OTHER);
    }
}
