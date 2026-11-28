<?php

declare(strict_types=1);

namespace App\Controller\Document;

use App\Entity\Document\Document;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Document\SlipNature;
use App\Enum\Document\SlipPlace;
use App\Repository\Document\DocumentRepository;
use App\Security\Voter\DossierVoter;
use App\Service\Accounting\Template\VatRate;
use App\Service\Document\Preparation\PiecePreparation;
use App\Service\Shared\PostValue;
use App\Service\Shared\QueryValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * A pièce in « mode préparation » (design-ux.md § 3.5; lot 2, step 2.2b): the teacher, in a template
 * they may change, sees the pièce on the left and on the right its read data and its proposed entry
 * as they really are; a value opens the panel « Glisser une erreur » (`?slip=` the value), whose
 * wrong value shows struck through in amber beside the right one until « Retirer l'erreur »; the
 * pièce may be made to arrive twice. « Voir comme l'étudiant » is the pièce's own sheet. Anything
 * else - a student's dossier, a pièce posted or set aside - is not there: the answer key never
 * leaves this screen.
 */
#[Route('/d/{dossier}/documents/{id}/prepare', requirements: ['dossier' => '\d+', 'id' => '\d+'])]
final class PiecePreparationController extends AbstractController
{
    #[Route('', name: 'app_document_prepare', methods: ['GET'])]
    public function show(Dossier $dossier, int $id, Request $request, DocumentRepository $documents, PiecePreparation $preparation): Response
    {
        $document = $this->preparable($dossier, $id, $documents);
        $target = QueryValue::trimmed($request, 'slip');

        return $this->screen($dossier, $document, $preparation, '' === $target ? null : $target, null);
    }

    #[Route('', name: 'app_document_prepare_act', methods: ['POST'])]
    public function act(Dossier $dossier, int $id, Request $request, DocumentRepository $documents, PiecePreparation $preparation, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $document = $this->preparable($dossier, $id, $documents);
        if (!$this->isCsrfTokenValid('prepare-'.$document->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not yours to change.');
        }
        $action = PostValue::trimmed($request, '_action');
        $target = PostValue::trimmed($request, 'target');
        [$refusal, $done] = match ($action) {
            'slip' => [$this->slip($document, $target, $request, $preparation, $me), 'Erreur glissée.'],
            'remove' => [$preparation->remove($document, $target, $me), 'Erreur retirée : la valeur juste est rétablie.'],
            'account' => [$preparation->fixAccount($document, (int) PostValue::nullableInt($request, 'line'), PostValue::trimmed($request, 'account'), $me), 'Écriture juste enregistrée.'],
            'duplicate' => [$preparation->duplicate($document, $me), 'Doublon glissé : la pièce arrive deux fois dans la boîte de réception.'],
            'unduplicate' => [$preparation->withdrawDuplicate($document, $me), 'Doublon retiré : la pièce n’arrive plus qu’une fois.'],
            default => throw new NotFoundHttpException('No such gesture.'),
        };
        if (null !== $refusal) {
            if (\in_array($action, ['slip', 'account'], true)) {
                return $this->screen($dossier, $document, $preparation, $target, $refusal, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $this->addFlash('status', $refusal);

            return $this->redirectToRoute('app_document_prepare', ['dossier' => $dossier->getId(), 'id' => $document->getId()], Response::HTTP_SEE_OTHER);
        }
        $entityManager->flush();
        $this->addFlash('status', $done);

        return $this->redirectToRoute('app_document_prepare', ['dossier' => $dossier->getId(), 'id' => $document->getId()], Response::HTTP_SEE_OTHER);
    }

    /** The wrong value: a suggestion ticked, else what was typed in the field of its nature. */
    private function slip(Document $document, string $target, Request $request, PiecePreparation $preparation, User $me): ?string
    {
        $place = SlipPlace::tryFrom(PostValue::trimmed($request, 'place'));
        $panel = $preparation->panel($document, $target);
        if (null === $place || null === $panel || null === $panel->nature) {
            return 'Choisissez où l’erreur apparaît.';
        }
        $wrong = PostValue::trimmed($request, 'wrong');
        if ('' === $wrong) {
            $free = PostValue::trimmed($request, 'free');
            $rate = VatRate::parse($free);
            $wrong = match ($panel->nature) {
                SlipNature::Amount => (string) (PostValue::cents($request, 'free_amount') ?? ''),
                SlipNature::VatRate => null === $rate ? $free : VatRate::canonical($rate),
                SlipNature::Account => strtoupper($free),
                SlipNature::ThirdParty => PostValue::trimmed($request, 'free_party'),
            };
        }
        if ('' === $wrong) {
            return 'Choisissez une valeur fausse, ou saisissez-la.';
        }

        return $preparation->slip($document, $target, $place, $wrong, $me);
    }

    private function screen(Dossier $dossier, Document $document, PiecePreparation $preparation, ?string $target, ?string $error, int $status = Response::HTTP_OK): Response
    {
        return $this->render('document/prepare.html.twig', [
            'dossier' => $dossier,
            'document' => $document,
            'prep' => $preparation->view($document),
            'panel' => null === $target ? null : $preparation->panel($document, $target),
            'panel_error' => $error,
        ], new Response(status: $status));
    }

    /** An open pièce of a template the teacher may change; anything else is not there. */
    private function preparable(Dossier $dossier, int $id, DocumentRepository $documents): Document
    {
        $document = $documents->findOneInDossier($dossier, $id);
        if (null === $document || !PiecePreparation::isPreparable($document) || !$this->isGranted(DossierVoter::EDIT, $dossier)) {
            throw new NotFoundHttpException('No pièce of yours to prepare.');
        }

        return $document;
    }
}
