<?php

declare(strict_types=1);

namespace App\Controller\Accounting;

use App\Entity\Accounting\Entry;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Repository\Accounting\EntryRepository;
use App\Security\Voter\DossierVoter;
use App\Service\Accounting\Entry\GridDrafts;
use App\Service\Accounting\Entry\GridGroup;
use App\Service\Accounting\Entry\JournalGrid;
use App\Service\Accounting\Ledger\EntryRefused;
use App\Service\Accounting\Ledger\Ledger;
use App\Service\Shared\DateValue;
use App\Service\Shared\PostValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * An entry on its own (design-ui.md § 4.8: the grid's card, read only): its lines and totals, its
 * state and number, and the gestures of its state (design-ux.md § 6.3) - a draft is changed in the
 * grid, deleted, validated (confirmation bar, § 6.2) or duplicated; a validated entry is reversed
 * (« Contrepasser », or « Contrepasser et ressaisir », which also puts a copy back in the grid) or
 * duplicated. A duplicate is a new entry of the grid, pending, never written before F10.
 */
#[Route('/d/{dossier}/accounting/entries/{id}', requirements: ['dossier' => '\d+', 'id' => '\d+'])]
final class EntryController extends AbstractController
{
    #[Route('', name: 'app_accounting_entry_show', methods: ['GET'])]
    public function show(Dossier $dossier, int $id, EntryRepository $entries): Response
    {
        $entry = $entries->find($id) ?? throw new NotFoundHttpException('No such entry.');

        return $this->render('accounting/entry.html.twig', [
            'dossier' => $dossier,
            'entry' => $entry,
            'reversal' => $entry->isValidated() ? $entries->findReversalOf($entry) : null,
            'reverse_error' => null,
        ]);
    }

    #[Route('/validate', name: 'app_accounting_entry_validate_one', methods: ['POST'])]
    public function validate(Dossier $dossier, int $id, Request $request, EntryRepository $entries, Ledger $ledger, #[CurrentUser] User $me): Response
    {
        $entry = $this->editable($dossier, $id, $request, $entries, 'validate');
        try {
            $ledger->validate([$entry], $me);
            $this->addFlash('status', \sprintf('Écriture %s validée.', $entry->getNumberLabel()));
        } catch (EntryRefused $refusal) {
            $this->addFlash('status', $refusal->getMessage());
        }

        return $this->back($dossier, $entry);
    }

    #[Route('/delete', name: 'app_accounting_entry_delete', methods: ['POST'])]
    public function delete(Dossier $dossier, int $id, Request $request, EntryRepository $entries, Ledger $ledger, #[CurrentUser] User $me): Response
    {
        $entry = $this->editable($dossier, $id, $request, $entries, 'delete');
        $grid = $this->gridOf($dossier, $entry);
        try {
            $ledger->discard($entry, $me);
            $this->addFlash('status', \sprintf('Écriture %s du %s supprimée du brouillard.', $entry->getDocument(), $entry->getEntryDate()->format('d/m/Y')));
        } catch (EntryRefused $refusal) {
            $this->addFlash('status', $refusal->getMessage());

            return $this->back($dossier, $entry);
        }

        return $grid;
    }

    #[Route('/reverse', name: 'app_accounting_entry_reverse', methods: ['POST'])]
    public function reverse(Dossier $dossier, int $id, Request $request, EntryRepository $entries, Ledger $ledger, JournalGrid $grid, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        $entry = $this->editable($dossier, $id, $request, $entries, 'reverse');
        $typed = PostValue::trimmed($request, 'date');
        $on = DateValue::parse($typed);
        $error = null === $on ? ('' === $typed ? 'Indiquez la date de la contrepassation.' : 'La date doit s’écrire jj/mm/aaaa, par exemple 12/11/2026.') : null;
        if (null !== $on) {
            try {
                $reversal = $ledger->reverse($entry, $on, $me);
                if ('1' === PostValue::trimmed($request, 'again')) {
                    $this->copyToGrid($grid, $entry, $me, $clock);
                    $this->addFlash('status', \sprintf('Écriture %s contrepassée par %s ; sa copie attend dans la grille.', $entry->getNumberLabel(), $reversal->getNumberLabel()));

                    return $this->gridOf($dossier, $entry, true);
                }
                $this->addFlash('status', \sprintf('Écriture %s contrepassée par %s.', $entry->getNumberLabel(), $reversal->getNumberLabel()));

                return $this->back($dossier, $entry);
            } catch (EntryRefused $refusal) {
                $error = $refusal->getMessage();
            }
        }

        return $this->render('accounting/entry.html.twig', [
            'dossier' => $dossier,
            'entry' => $entry,
            'reversal' => $entries->findReversalOf($entry),
            'reverse_error' => $error,
            'reverse_typed' => $typed,
        ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/duplicate', name: 'app_accounting_entry_duplicate', methods: ['POST'])]
    public function duplicate(Dossier $dossier, int $id, Request $request, EntryRepository $entries, JournalGrid $grid, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        $entry = $this->editable($dossier, $id, $request, $entries, 'duplicate');
        if ($entry->getPeriod()->isClosed() || $entry->getPeriod()->getExercise()->isClosed()) {
            $this->addFlash('status', 'Le mois de cette écriture est clos : sa copie ne peut pas s’y saisir.');

            return $this->back($dossier, $entry);
        }
        $this->copyToGrid($grid, $entry, $me, $clock);
        $this->addFlash('status', \sprintf('Copie de l’écriture %s ajoutée à la grille : modifiez-la, puis F10.', $entry->getNumberLabel() ?? $entry->getDocument()));

        return $this->gridOf($dossier, $entry, true);
    }

    private function copyToGrid(JournalGrid $grid, Entry $entry, User $me, ClockInterface $clock): void
    {
        $journal = $entry->getJournal();
        $copy = new GridGroup('c'.bin2hex(random_bytes(6)), null, $entry->getEntryDate()->format('Y-m-d'), $entry->getDocument(), GridDrafts::linesOf($entry, $journal->getCounterpartAccount()?->getNumber()));
        $grid->append($journal, $entry->getPeriod(), $me, $copy, $clock->now());
    }

    /** The entry, for whoever works in the dossier and sent the form's token. */
    private function editable(Dossier $dossier, int $id, Request $request, EntryRepository $entries, string $gesture): Entry
    {
        $entry = $entries->find($id) ?? throw new NotFoundHttpException('No such entry.');
        if (!$this->isGranted(DossierVoter::EDIT, $dossier) || !$this->isCsrfTokenValid('entry-'.$gesture.'-'.$entry->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not yours to change.');
        }

        return $entry;
    }

    private function back(Dossier $dossier, Entry $entry): RedirectResponse
    {
        return $this->redirectToRoute('app_accounting_entry_show', ['dossier' => $dossier->getId(), 'id' => $entry->getId()], Response::HTTP_SEE_OTHER);
    }

    /** The grid of the entry's journal and month; `last` puts the focus on its last entry (a copy). */
    private function gridOf(Dossier $dossier, Entry $entry, bool $last = false): RedirectResponse
    {
        return $this->redirectToRoute('app_accounting_entry', [
            'dossier' => $dossier->getId(),
            'journal' => $entry->getJournal()->getCode(),
            'period' => $entry->getPeriod()->getStartsOn()->format('Y-m-d'),
            ...($last ? ['entry' => -1] : []),
        ], Response::HTTP_SEE_OTHER);
    }
}
