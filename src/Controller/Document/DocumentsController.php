<?php

declare(strict_types=1);

namespace App\Controller\Document;

use App\Entity\Document\Document;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Document\DocumentState;
use App\Enum\Document\DocumentType;
use App\Repository\Document\DocumentRepository;
use App\Security\Voter\DossierVoter;
use App\Service\Accounting\Chart\AccountSearch;
use App\Service\Document\Documents;
use App\Service\Shared\ListPage;
use App\Service\Shared\PostValue;
use App\Service\Shared\QueryValue;
use App\Service\Shared\Storage\UploadRefusal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Pièces › Boîte de réception (gabarit L, design-ux.md § 3.2): the dossier's pièces, the latest
 * arrived first, filtered by state and searched by number, third party or file name; Entrée opens
 * a pièce's sheet. « Déposer une pièce » (whoever may change the dossier) takes a PDF or a picture
 * and its nature, then opens the sheet where its read data are typed.
 */
#[Route('/d/{dossier}/documents', requirements: ['dossier' => '\d+'])]
final class DocumentsController extends AbstractController
{
    #[Route('', name: 'app_documents', methods: ['GET'])]
    public function index(Dossier $dossier, Request $request, DocumentRepository $documents): Response
    {
        $all = $documents->findByDossier($dossier);
        $search = QueryValue::trimmed($request, 'q');
        $state = DocumentState::tryFrom(QueryValue::trimmed($request, 'state'));

        $found = '' === $search ? $all : array_values(array_filter($all, static fn (Document $document): bool => self::matches($document, $search)));
        $counts = ['' => \count($found)];
        foreach (DocumentState::cases() as $case) {
            $counts[$case->value] = 0;
        }
        foreach ($found as $document) {
            ++$counts[$document->getState()->value];
        }
        $shown = null === $state ? $found : array_values(array_filter($found, static fn (Document $d): bool => $d->getState() === $state));
        $page = ListPage::fromRequest($request, 'documents')->within(\count($shown));

        return $this->render('document/index.html.twig', [
            'dossier' => $dossier,
            'total' => \count($all),
            'document_rows' => \array_slice($shown, $page->offset(), $page->perPage),
            'shown_total' => \count($shown),
            'list_page' => $page,
            'counts' => $counts,
            'states' => DocumentState::cases(),
            'state' => $state,
            'search' => $search,
        ]);
    }

    #[Route('/new', name: 'app_document_new', methods: ['GET', 'POST'])]
    public function deposit(Dossier $dossier, Request $request, Documents $documents, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        if (!$this->isGranted(DossierVoter::EDIT, $dossier)) {
            throw new NotFoundHttpException('Not yours to change.');
        }
        $type = QueryValue::trimmed($request, 'type');
        $type = null === DocumentType::tryFrom($type) ? '' : $type;
        $errors = [];
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('document-new-'.$dossier->getId(), $request->request->getString('_token'))) {
                throw new NotFoundHttpException('Not yours to change.');
            }
            $type = PostValue::trimmed($request, 'type');
            $file = $request->files->get('file');
            $nature = DocumentType::tryFrom($type);
            if (null === $nature) {
                $errors['type'] = 'Choisissez la nature de la pièce.';
            }
            if (!$file instanceof UploadedFile) {
                $errors['file'] = 'Choisissez le fichier de la pièce : un PDF ou une image.';
            } elseif (null !== $nature) {
                try {
                    $document = $documents->deposit($dossier, $file, $nature, $me);
                    $entityManager->flush();
                    $this->addFlash('status', \sprintf('Pièce %s déposée : saisissez ses données lues.', $document->getFileName()));

                    return $this->redirectToRoute('app_document', ['dossier' => $dossier->getId(), 'id' => $document->getId()], Response::HTTP_SEE_OTHER);
                } catch (UploadRefusal $refusal) {
                    $errors['file'] = $refusal->getMessage();
                }
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return $this->render('document/new.html.twig', [
            'dossier' => $dossier,
            'type' => $type,
            'errors' => $errors,
            'accept' => Documents::policy()->accept(),
            'type_options' => array_map(static fn (DocumentType $t): array => ['value' => $t->value, 'label' => $t->label()], DocumentType::cases()),
        ], new Response(status: $status));
    }

    /** Number, third party's name or code, file name: words without accents nor case. */
    private static function matches(Document $document, string $search): bool
    {
        $haystack = AccountSearch::fold(implode(' ', [
            $document->getNumber() ?? '',
            $document->getThirdParty()?->getName() ?? '',
            $document->getThirdParty()?->getCode() ?? '',
            $document->getFileName(),
        ]));
        foreach (preg_split('/\s+/', AccountSearch::fold($search)) ?: [] as $word) {
            if ('' !== $word && !str_contains($haystack, $word)) {
                return false;
            }
        }

        return true;
    }
}
