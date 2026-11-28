<?php

declare(strict_types=1);

namespace App\Controller\Payroll;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\Payroll\PayrollBody;
use App\Enum\Payroll\PayrollBodyKind;
use App\Repository\Payroll\PayrollBodyRepository;
use App\Security\Voter\DossierRightVoter;
use App\Service\Payroll\Settings\PayrollBodyDraft;
use App\Service\Payroll\Settings\PayrollBodySheet;
use App\Service\Shared\PostValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Paie et social › Paramètres de paie › Organismes (lot 4, step 4.1; gabarits L then F): the
 * collecting bodies of the dossier - Urssaf, complementary pension, provident fund, health
 * insurer - and the fiche of one. Read with PAYROLL_READ, changed with F10 under PAYROLL_SETTINGS.
 */
#[Route('/d/{dossier}/payroll/bodies', requirements: ['dossier' => '\d+'])]
final class PayrollBodiesController extends AbstractController
{
    #[Route('', name: 'app_payroll_bodies', methods: ['GET'])]
    public function index(Dossier $dossier, PayrollBodyRepository $bodies): Response
    {
        $this->denyUnlessReads($dossier);

        return $this->render('payroll/bodies.html.twig', ['dossier' => $dossier, 'body_rows' => $bodies->findByDossier($dossier)]);
    }

    #[Route('/new', name: 'app_payroll_body_new', methods: ['GET', 'POST'])]
    public function create(Dossier $dossier, Request $request, PayrollBodySheet $sheet, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        if (!$this->isGranted(DossierRightVoter::PAYROLL_SETTINGS, $dossier)) {
            throw new NotFoundHttpException('Not yours to change.');
        }
        $draft = new PayrollBodyDraft();
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            $this->read($request, $draft, 'payroll-body-new-'.$dossier->getId());
            $kind = $sheet->check($draft);
            if (null !== $kind) {
                $sheet->create($dossier, $draft, $kind, $me);
                $entityManager->flush();
                $this->addFlash('status', \sprintf('Organisme %s créé.', $draft->name));

                return $this->redirectToRoute('app_payroll_bodies', ['dossier' => $dossier->getId()], Response::HTTP_SEE_OTHER);
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return $this->render('payroll/body.html.twig', $this->view($dossier, $draft, null, null), new Response(status: $status));
    }

    #[Route('/{id}', name: 'app_payroll_body', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Dossier $dossier, int $id, Request $request, PayrollBodyRepository $bodies, PayrollBodySheet $sheet, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $this->denyUnlessReads($dossier);
        $body = $bodies->findOneInDossier($dossier, $id) ?? throw new NotFoundHttpException('No such body.');
        $draft = PayrollBodyDraft::of($body);
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            if (!$this->isGranted(DossierRightVoter::PAYROLL_SETTINGS, $dossier)) {
                throw new NotFoundHttpException('Not yours to change.');
            }
            $this->read($request, $draft, 'payroll-body-'.$id);
            $kind = $sheet->check($draft, $body);
            if (null !== $kind) {
                $changed = $sheet->update($body, $draft, $kind, $me);
                $entityManager->flush();
                $this->addFlash('status', $changed ? \sprintf('Organisme %s enregistré.', $draft->name) : 'Rien à enregistrer : l’organisme est inchangé.');

                return $this->redirectToRoute('app_payroll_body', ['dossier' => $dossier->getId(), 'id' => $id], Response::HTTP_SEE_OTHER);
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return $this->render('payroll/body.html.twig', $this->view($dossier, $draft, $body, $sheet->deletionRefusal($body)), new Response(status: $status));
    }

    #[Route('/{id}/delete', name: 'app_payroll_body_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Dossier $dossier, int $id, Request $request, PayrollBodyRepository $bodies, PayrollBodySheet $sheet, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $body = $bodies->findOneInDossier($dossier, $id) ?? throw new NotFoundHttpException('No such body.');
        if (!$this->isGranted(DossierRightVoter::PAYROLL_SETTINGS, $dossier) || !$this->isCsrfTokenValid('payroll-body-delete-'.$id, $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not yours to change.');
        }
        $refusal = $sheet->deletionRefusal($body);
        if (null !== $refusal) {
            $this->addFlash('status', $refusal);

            return $this->redirectToRoute('app_payroll_body', ['dossier' => $dossier->getId(), 'id' => $id], Response::HTTP_SEE_OTHER);
        }
        $name = $body->getName();
        $sheet->delete($body, $me);
        $entityManager->flush();
        $this->addFlash('status', \sprintf('Organisme %s supprimé.', $name));

        return $this->redirectToRoute('app_payroll_bodies', ['dossier' => $dossier->getId()], Response::HTTP_SEE_OTHER);
    }

    private function denyUnlessReads(Dossier $dossier): void
    {
        if (!$this->isGranted(DossierRightVoter::PAYROLL_READ, $dossier)) {
            throw new NotFoundHttpException('The payroll is closed to this profile.');
        }
    }

    /** @return array<string, mixed> */
    private function view(Dossier $dossier, PayrollBodyDraft $draft, ?PayrollBody $body, ?string $deletionRefusal): array
    {
        return [
            'dossier' => $dossier,
            'draft' => $draft,
            'body' => $body,
            'kind_options' => array_map(static fn (PayrollBodyKind $kind): array => ['value' => $kind->value, 'label' => $kind->label()], PayrollBodyKind::cases()),
            'deletion_refusal' => $deletionRefusal,
        ];
    }

    private function read(Request $request, PayrollBodyDraft $draft, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not yours to change.');
        }
        $draft->kind = PostValue::trimmed($request, 'kind');
        $draft->name = PostValue::trimmed($request, 'name');
        $draft->reference = PostValue::trimmed($request, 'reference');
    }
}
