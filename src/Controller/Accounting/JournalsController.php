<?php

declare(strict_types=1);

namespace App\Controller\Accounting;

use App\Entity\Accounting\Journal;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Accounting\JournalType;
use App\Repository\Accounting\AccountRepository;
use App\Repository\Accounting\JournalRepository;
use App\Security\Voter\DossierVoter;
use App\Service\Accounting\Journal\JournalDraft;
use App\Service\Accounting\Journal\JournalSheet;
use App\Service\Shared\PostValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Comptabilité › Paramètres comptables › Journaux (design-ux.md § 3.2, gabarits L then F): the
 * dossier's journals, and the fiche of one - code, label, type, counterpart account picked with F4.
 * F10 saves for whoever works in the dossier (DossierVoter::EDIT), anybody else reads. The address
 * of a journal is its code.
 */
#[Route('/d/{dossier}/accounting/journals', requirements: ['dossier' => '\d+'])]
final class JournalsController extends AbstractController
{
    #[Route('', name: 'app_accounting_journals', methods: ['GET'])]
    public function index(Dossier $dossier, JournalRepository $journals): Response
    {
        return $this->render('accounting/journals.html.twig', [
            'dossier' => $dossier,
            'journal_rows' => $journals->findByDossier($dossier),
        ]);
    }

    #[Route('/new', name: 'app_accounting_journal_new', methods: ['GET', 'POST'])]
    public function create(Dossier $dossier, Request $request, JournalSheet $sheet, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        if (!$this->isGranted(DossierVoter::EDIT, $dossier)) {
            throw new NotFoundHttpException('Not yours to change.');
        }
        $draft = new JournalDraft();
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            $this->read($request, $draft, 'dossier-journal-new-'.$dossier->getId());
            $fields = $sheet->check($draft, $dossier);
            if (null !== $fields) {
                $sheet->create($dossier, $draft, $fields, $me);
                $entityManager->flush();
                $this->addFlash('status', \sprintf('Journal %s créé.', $draft->code));

                return $this->redirectToRoute('app_accounting_journals', ['dossier' => $dossier->getId()], Response::HTTP_SEE_OTHER);
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return $this->render('accounting/journal.html.twig', ['dossier' => $dossier, 'draft' => $draft, 'journal' => null, 'types' => JournalType::cases(), 'deletion_refusal' => null], new Response(status: $status));
    }

    #[Route('/{code}', name: 'app_accounting_journal', requirements: ['code' => '[A-Z0-9]{1,6}'], methods: ['GET', 'POST'])]
    public function edit(Dossier $dossier, string $code, Request $request, JournalRepository $journals, JournalSheet $sheet, AccountRepository $accounts, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $journal = $journals->findOneByCode($dossier, $code) ?? throw new NotFoundHttpException('No such journal.');
        $draft = JournalDraft::of($journal);
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            if (!$this->isGranted(DossierVoter::EDIT, $dossier)) {
                throw new NotFoundHttpException('Not yours to change.');
            }
            $this->read($request, $draft, 'dossier-journal-'.$journal->getId());
            $fields = $sheet->check($draft, $dossier, $journal);
            if (null !== $fields) {
                $changed = $sheet->update($journal, $draft, $fields, $me);
                $entityManager->flush();
                $this->addFlash('status', $changed ? \sprintf('Journal %s enregistré.', $draft->code) : 'Rien à enregistrer : le journal est inchangé.');

                return $this->redirectToRoute('app_accounting_journal', ['dossier' => $dossier->getId(), 'code' => $draft->code], Response::HTTP_SEE_OTHER);
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return $this->render('accounting/journal.html.twig', [
            'dossier' => $dossier,
            'draft' => $draft,
            'journal' => $journal,
            'types' => JournalType::cases(),
            'counterpart_label' => '' === $draft->counterpart ? null : $accounts->findOneByNumber($dossier, $draft->counterpart)?->getLabel(),
            'deletion_refusal' => $sheet->deletionRefusal($journal),
        ], new Response(status: $status));
    }

    #[Route('/{code}/delete', name: 'app_accounting_journal_delete', requirements: ['code' => '[A-Z0-9]{1,6}'], methods: ['POST'])]
    public function delete(Dossier $dossier, string $code, Request $request, JournalRepository $journals, JournalSheet $sheet, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $journal = $journals->findOneByCode($dossier, $code) ?? throw new NotFoundHttpException('No such journal.');
        if (!$this->isGranted(DossierVoter::EDIT, $dossier) || !$this->isCsrfTokenValid('dossier-journal-delete-'.$journal->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not yours to change.');
        }
        $refusal = $sheet->deletionRefusal($journal);
        if (null !== $refusal) {
            $this->addFlash('status', $refusal);

            return $this->redirectToRoute('app_accounting_journal', ['dossier' => $dossier->getId(), 'code' => $code], Response::HTTP_SEE_OTHER);
        }
        $sheet->delete($journal, $me);
        $entityManager->flush();
        $this->addFlash('status', \sprintf('Journal %s supprimé.', $code));

        return $this->redirectToRoute('app_accounting_journals', ['dossier' => $dossier->getId()], Response::HTTP_SEE_OTHER);
    }

    private function read(Request $request, JournalDraft $draft, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not yours to change.');
        }
        $draft->code = PostValue::trimmed($request, 'code');
        $draft->label = PostValue::trimmed($request, 'label');
        $draft->type = PostValue::trimmed($request, 'type');
        $draft->counterpart = PostValue::trimmed($request, 'counterpart');
    }
}
