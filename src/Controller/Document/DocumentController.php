<?php

declare(strict_types=1);

namespace App\Controller\Document;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Document\DocumentType;
use App\Repository\Accounting\EntryRepository;
use App\Repository\Document\DocumentRepository;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Security\Voter\DossierVoter;
use App\Service\Document\DocumentReading;
use App\Service\Document\Documents;
use App\Service\Shared\PostValue;
use App\Service\Shared\Storage\DossierFiles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * A pièce's sheet (gabarit P, left half: design-ux.md § 3.5): the viewer, and on its right the
 * read data - typed by hand for a deposit, F10 saves them while the pièce is open (whoever may
 * change the dossier) - then the entries carrying its number as their pièce reference. Its file
 * is served here, inline or as a download, to whoever opens the dossier: the storage is private.
 */
#[Route('/d/{dossier}/documents', requirements: ['dossier' => '\d+'])]
final class DocumentController extends AbstractController
{
    #[Route('/{id}', name: 'app_document', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function show(Dossier $dossier, int $id, Request $request, DocumentRepository $documents, ThirdPartyRepository $thirdParties, EntryRepository $entries, Documents $door, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $document = $documents->findOneInDossier($dossier, $id) ?? throw new NotFoundHttpException('No such pièce.');
        $canEdit = $this->isGranted(DossierVoter::EDIT, $dossier) && $document->getState()->isOpen();
        $reading = DocumentReading::of($document);
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            if (!$canEdit || !$this->isCsrfTokenValid('document-'.$document->getId(), $request->request->getString('_token'))) {
                throw new NotFoundHttpException('Not yours to change.');
            }
            $this->read($request, $reading);
            if ($door->saveReading($document, $reading, $me)) {
                $entityManager->flush();
                $this->addFlash('status', 'Données lues enregistrées.');

                return $this->redirectToRoute('app_document', ['dossier' => $dossier->getId(), 'id' => $document->getId()], Response::HTTP_SEE_OTHER);
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        $number = $document->getNumber();

        return $this->render('document/show.html.twig', [
            'dossier' => $dossier,
            'document' => $document,
            'reading' => $reading,
            'can_edit' => $canEdit,
            'entries' => null === $number ? [] : $entries->findByDocumentReference($dossier, $number),
            'type_options' => array_map(static fn (DocumentType $t): array => ['value' => $t->value, 'label' => $t->label()], DocumentType::cases()),
            'third_party_options' => array_map(static fn ($t): array => ['value' => (string) $t->getId(), 'label' => $t->getCode().' · '.$t->getName()], $thirdParties->findByDossier($dossier)),
        ], new Response(status: $status));
    }

    #[Route('/{id}/file', name: 'app_document_file', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function download(Dossier $dossier, int $id, Request $request, DocumentRepository $documents, DossierFiles $files): Response
    {
        $document = $documents->findOneInDossier($dossier, $id) ?? throw new NotFoundHttpException('No such pièce.');
        $dossierId = (int) $dossier->getId();
        $key = $document->getFileKey();
        if (!$files->exists($dossierId, $key)) {
            throw new NotFoundHttpException('The pièce’s file is missing.');
        }
        $response = new StreamedResponse(static function () use ($files, $dossierId, $key): void {
            $stream = $files->readStream($dossierId, $key);
            fpassthru($stream);
            fclose($stream);
        });
        $response->headers->set('Content-Type', $document->getMediaType());
        $response->headers->set('Content-Length', (string) $files->size($dossierId, $key));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store');
        $disposition = $request->query->getBoolean('download') ? HeaderUtils::DISPOSITION_ATTACHMENT : HeaderUtils::DISPOSITION_INLINE;
        $fallback = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $document->getFileName());
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition($disposition, $document->getFileName(), '' === trim($fallback, '-.') ? 'piece' : $fallback));

        return $response;
    }

    private function read(Request $request, DocumentReading $reading): void
    {
        foreach (['type', 'thirdParty', 'number', 'date', 'dueDate', 'orderReference'] as $field) {
            $reading->{$field} = PostValue::trimmed($request, $field);
        }
        for ($index = 0; $index < DocumentReading::VAT_ROWS; ++$index) {
            $reading->vat[$index] = [
                'rate' => PostValue::trimmed($request, 'vat_rate_'.$index),
                'base' => PostValue::cents($request, 'vat_base_'.$index),
                'vat' => PostValue::cents($request, 'vat_amount_'.$index),
            ];
        }
        $reading->totalExcludingTax = PostValue::cents($request, 'totalExcludingTax');
        $reading->totalVat = PostValue::cents($request, 'totalVat');
        $reading->totalIncludingTax = PostValue::cents($request, 'totalIncludingTax');
    }
}
