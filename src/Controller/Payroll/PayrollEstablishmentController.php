<?php

declare(strict_types=1);

namespace App\Controller\Payroll;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Repository\Payroll\PayrollEstablishmentRepository;
use App\Security\Voter\DossierRightVoter;
use App\Service\Payroll\Settings\PayrollEstablishmentDraft;
use App\Service\Payroll\Settings\PayrollEstablishmentRules;
use App\Service\Payroll\Settings\PayrollEstablishmentSheet;
use App\Service\Shared\PostValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Paie et social › Paramètres de paie › Établissement (lot 4, step 4.1; gabarit F): the SIRET and
 * the APE code read from the fiche société, the headcount, the work accident rate notified, the
 * mobility payment rate, the collective working time. F10 saves under PAYROLL_SETTINGS.
 */
#[Route('/d/{dossier}/payroll/establishment', requirements: ['dossier' => '\d+'])]
final class PayrollEstablishmentController extends AbstractController
{
    #[Route('', name: 'app_payroll_establishment', methods: ['GET', 'POST'])]
    public function edit(Dossier $dossier, Request $request, PayrollEstablishmentRepository $establishments, PayrollEstablishmentSheet $sheet, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        if (!$this->isGranted(DossierRightVoter::PAYROLL_READ, $dossier)) {
            throw new NotFoundHttpException('The payroll is closed to this profile.');
        }
        $establishment = $establishments->findOfDossier($dossier);
        $draft = null === $establishment ? new PayrollEstablishmentDraft() : PayrollEstablishmentDraft::of($establishment);
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            if (!$this->isGranted(DossierRightVoter::PAYROLL_SETTINGS, $dossier) || !$this->isCsrfTokenValid('payroll-establishment-'.$dossier->getId(), $request->request->getString('_token'))) {
                throw new NotFoundHttpException('Not yours to change.');
            }
            $draft->headcount = PostValue::trimmed($request, 'headcount');
            $draft->workAccidentRate = PostValue::trimmed($request, 'workAccidentRate');
            $draft->mobilityRate = PostValue::trimmed($request, 'mobilityRate');
            $draft->monthlyHours = PostValue::trimmed($request, 'monthlyHours');
            $fields = PayrollEstablishmentRules::check($draft);
            if (null !== $fields) {
                $changed = $sheet->save($dossier, $fields, $me);
                $entityManager->flush();
                $this->addFlash('status', $changed ? 'Établissement enregistré.' : 'Rien à enregistrer : l’établissement est inchangé.');

                return $this->redirectToRoute('app_payroll_establishment', ['dossier' => $dossier->getId()], Response::HTTP_SEE_OTHER);
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return $this->render('payroll/establishment.html.twig', ['dossier' => $dossier, 'draft' => $draft, 'establishment' => $establishment], new Response(status: $status));
    }
}
