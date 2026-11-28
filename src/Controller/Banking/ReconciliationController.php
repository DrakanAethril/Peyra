<?php

declare(strict_types=1);

namespace App\Controller\Banking;

use App\Entity\Banking\BankStatement;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Repository\Banking\BankStatementRepository;
use App\Repository\Dossier\ExerciseRepository;
use App\Security\Voter\DossierRightVoter;
use App\Service\Accounting\Edition\Editions;
use App\Service\Accounting\Edition\ReportHeader;
use App\Service\Accounting\Entry\AssistanceLevels;
use App\Service\Accounting\Template\VatRate;
use App\Service\Banking\BankingRefused;
use App\Service\Banking\Reconciliation\Pointings;
use App\Service\Banking\Reconciliation\ReconciliationBoard;
use App\Service\Banking\Reconciliation\ReconciliationRules;
use App\Service\Banking\Statement\BankStatements;
use App\Service\Shared\Assistance\Assistance;
use App\Service\Shared\Pdf\PdfRenderer;
use App\Service\Shared\PostValue;
use App\Service\Shared\QueryValue;
use App\Service\Shared\ReturnPath;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Banque › Rapprochement (step 2.6a; mock-up 2, design-ux.md § 4.5, gabarit X): the statement on
 * the left, the bank account's movements on the right, the état de rapprochement built below as
 * one points; Espace chooses, Tab changes side, Ctrl Entrée points, Suppr or Ctrl Z unpoints; the
 * panel « Ligne sélectionnée » writes the entry a line without one asks for, from its assignment
 * rule at G and B; « Valider le rapprochement » after the confirmation bar; « Éditer l'état » is the
 * état as a PDF. Every gesture goes through Pointings.
 */
#[Route('/d/{dossier}/bank/reconciliation', requirements: ['dossier' => '\d+'])]
final class ReconciliationController extends AbstractController
{
    use BankingControllerTrait;

    /** The statement to reconcile: the oldest not validated, else the latest; the empty state without any. */
    #[Route('', name: 'app_bank_reconciliation', methods: ['GET'])]
    public function index(Dossier $dossier, BankStatementRepository $statements, BankStatements $bank): Response
    {
        $readable = [];
        foreach ($statements->findLatestFirst($dossier) as $statement) {
            if ($this->isGranted(DossierRightVoter::JOURNAL_READ, $statement->getJournal())) {
                $readable[] = $statement;
            }
        }
        $chosen = $readable[0] ?? null;
        foreach (array_reverse($readable) as $statement) {
            if (!$statement->isReconciled()) {
                $chosen = $statement;
                break;
            }
        }
        if (null !== $chosen) {
            return $this->redirectToRoute('app_bank_reconciliation_show', ['dossier' => $dossier->getId(), 'id' => $chosen->getId()]);
        }

        return $this->render('banking/reconciliation_empty.html.twig', [
            'dossier' => $dossier,
            'can_import' => [] !== array_filter($bank->bankJournals($dossier), fn ($journal): bool => $this->isGranted(DossierRightVoter::JOURNAL_ENTER, $journal)),
        ]);
    }

    #[Route('/{id}', name: 'app_bank_reconciliation_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Dossier $dossier, int $id, Request $request, BankStatementRepository $statements, ReconciliationBoard $board, AssistanceLevels $assistance): Response
    {
        $statement = $this->readableStatement($dossier, $id, $statements);
        $level = Assistance::at($assistance->for($dossier));
        $view = $board->view($statement, $level);
        $selected = $view->line(QueryValue::int($request, 'line'));
        if (null !== $selected && null !== $view->lineLetter($selected)) {
            $selected = null;
        }
        if (null === $selected && !$statement->isReconciled()) {
            foreach ($view->report->toPost as $item) {
                $selected = $view->line($item->id);
                break;
            }
        }
        $others = array_values(array_filter($statements->findLatestFirst($dossier), fn (BankStatement $other): bool => $this->isGranted(DossierRightVoter::JOURNAL_READ, $other->getJournal())));

        return $this->render('banking/reconciliation.html.twig', [
            'dossier' => $dossier,
            'statement' => $statement,
            'rec' => $view,
            'assistance' => $level,
            'selected' => $selected,
            'selected_rule' => null === $selected ? null : ($view->rules[(int) $selected->getId()] ?? null),
            'validation_note' => ReconciliationRules::validationRefusal($view->report),
            'other_statements' => $others,
            'can_point' => $this->isGranted(DossierRightVoter::JOURNAL_ENTER, $statement->getJournal()) && !$statement->isReconciled(),
            'can_validate' => $this->isGranted(DossierRightVoter::JOURNAL_VALIDATE, $statement->getJournal()),
            'last' => QueryValue::trimmed($request, 'last'),
            'here' => ReturnPath::here($request->getPathInfo(), (string) $request->getQueryString()),
        ]);
    }

    #[Route('/{id}/point', name: 'app_bank_reconciliation_point', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function point(Dossier $dossier, int $id, Request $request, BankStatementRepository $statements, Pointings $pointings, #[CurrentUser] User $me): Response
    {
        $statement = $this->writable($dossier, $id, $request, $statements);
        $last = null;
        try {
            $last = $pointings->point($statement, PostValue::intList($request, 'lines'), PostValue::intList($request, 'entries'), $me);
            $this->addFlash('status', \sprintf('Pointé %s.', $last));
        } catch (BankingRefused $refused) {
            $this->addFlash('bank_fault', $refused->getMessage());
        }

        return $this->back($statement, ['last' => $last]);
    }

    #[Route('/{id}/unpoint', name: 'app_bank_reconciliation_unpoint', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function unpoint(Dossier $dossier, int $id, Request $request, BankStatementRepository $statements, Pointings $pointings, #[CurrentUser] User $me): Response
    {
        $statement = $this->writable($dossier, $id, $request, $statements);
        $letter = PostValue::trimmed($request, 'letter');
        try {
            $pointings->unpoint($statement, $letter, $me);
            $this->addFlash('status', \sprintf('Pointage %s défait.', mb_strtoupper($letter)));
        } catch (BankingRefused $refused) {
            $this->addFlash('bank_fault', $refused->getMessage());
        }

        return $this->back($statement);
    }

    #[Route('/{id}/proposals', name: 'app_bank_reconciliation_proposals', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function proposals(Dossier $dossier, int $id, Request $request, BankStatementRepository $statements, Pointings $pointings, #[CurrentUser] User $me): Response
    {
        $statement = $this->writable($dossier, $id, $request, $statements);
        try {
            $letters = $pointings->pointProposals($statement, $me);
            $this->addFlash('status', \sprintf('%d pointage%s proposé%s confirmé%s (%s).', \count($letters), \count($letters) > 1 ? 's' : '', \count($letters) > 1 ? 's' : '', \count($letters) > 1 ? 's' : '', implode(', ', $letters)));
        } catch (BankingRefused $refused) {
            $this->addFlash('bank_fault', $refused->getMessage());
        }

        return $this->back($statement);
    }

    #[Route('/{id}/generate', name: 'app_bank_reconciliation_generate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function generate(Dossier $dossier, int $id, Request $request, BankStatementRepository $statements, Pointings $pointings, #[CurrentUser] User $me): Response
    {
        $statement = $this->writable($dossier, $id, $request, $statements);
        $line = PostValue::nullableInt($request, 'line') ?? 0;
        $rate = PostValue::trimmed($request, 'vat_rate');
        $parsed = '' === $rate ? null : VatRate::parse($rate);
        if ('' !== $rate && null === $parsed) {
            $this->addFlash('bank_fault', 'Le taux de TVA s’écrit en pour cent : 20, 10, 5,5…');

            return $this->back($statement, ['line' => (string) $line]);
        }
        try {
            $entry = $pointings->generate($statement, $line, PostValue::trimmed($request, 'account'), null === $parsed ? null : (string) $parsed, PostValue::trimmed($request, 'label'), $me);
            $this->addFlash('status', \sprintf('Écriture %s passée au brouillard du journal %s et pointée.', $entry->getDocument(), $statement->getJournal()->getCode()));
        } catch (BankingRefused $refused) {
            $this->addFlash('bank_fault', $refused->getMessage());

            return $this->back($statement, ['line' => (string) $line]);
        }

        return $this->back($statement);
    }

    #[Route('/{id}/validate', name: 'app_bank_reconciliation_validate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function validate(Dossier $dossier, int $id, Request $request, BankStatementRepository $statements, Pointings $pointings, #[CurrentUser] User $me): Response
    {
        $statement = $this->readableStatement($dossier, $id, $statements);
        $this->refuseBadToken($dossier, $request);
        try {
            $warning = $pointings->validate($statement, $me);
            $this->addFlash('status', \sprintf('Rapprochement du %s validé.', mb_strtolower($statement->getTitle())));
            if (null !== $warning) {
                $this->addFlash('bank_warning', $warning);
            }
        } catch (BankingRefused $refused) {
            $this->addFlash('bank_fault', $refused->getMessage());
        }

        return $this->back($statement);
    }

    #[Route('/{id}/reopen', name: 'app_bank_reconciliation_reopen', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reopen(Dossier $dossier, int $id, Request $request, BankStatementRepository $statements, Pointings $pointings, #[CurrentUser] User $me): Response
    {
        $statement = $this->readableStatement($dossier, $id, $statements);
        $this->refuseBadToken($dossier, $request);
        try {
            $pointings->reopen($statement, $me);
            $this->addFlash('status', \sprintf('Rapprochement du %s rouvert.', mb_strtolower($statement->getTitle())));
        } catch (BankingRefused $refused) {
            $this->addFlash('bank_fault', $refused->getMessage());
        }

        return $this->back($statement);
    }

    /** « Éditer l'état » (design-ux.md § 3.2, « État de rapprochement »): the état as a PDF, A4 portrait. */
    #[Route('/{id}/state.pdf', name: 'app_bank_reconciliation_pdf', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function pdf(Dossier $dossier, int $id, BankStatementRepository $statements, ReconciliationBoard $board, AssistanceLevels $assistance, ExerciseRepository $exercises, PdfRenderer $renderer): Response
    {
        $statement = $this->readableStatement($dossier, $id, $statements);
        $view = $board->view($statement, Assistance::at($assistance->for($dossier)));
        $exercise = $exercises->findAt($dossier, $statement->getEndsOn());
        $title = 'État de rapprochement';
        $header = new ReportHeader(
            $dossier->getName(),
            $title,
            null === $exercise ? 'Hors exercice' : 'Exercice '.$exercise->getCode(),
            \sprintf('%s · %s · compte %s · au %s', $statement->getJournal()->getLabel(), $statement->getTitle(), $view->account->getNumber(), $statement->getEndsOn()->format('d/m/Y')),
            $dossier->getDossierDate(),
            Editions::student($dossier),
        );
        $file = (new AsciiSlugger('fr'))->slug('etat de rapprochement '.$statement->getJournal()->getCode().' '.$statement->getNumber().' '.$dossier->getName())->lower()->toString().'.pdf';

        return new Response($renderer->renderTemplate('banking/pdf/reconciliation.html.twig', ['report_header' => $header, 'rec' => $view, 'statement' => $statement]), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $file),
        ]);
    }

    private function writable(Dossier $dossier, int $id, Request $request, BankStatementRepository $statements): BankStatement
    {
        $statement = $this->readableStatement($dossier, $id, $statements);
        $this->refuseBadToken($dossier, $request);
        if (!$this->isGranted(DossierRightVoter::JOURNAL_ENTER, $statement->getJournal())) {
            throw new NotFoundHttpException('Not yours to point.');
        }

        return $statement;
    }

    /** @param array<string, ?string> $extra */
    private function back(BankStatement $statement, array $extra = []): RedirectResponse
    {
        return $this->redirectToRoute('app_bank_reconciliation_show', array_filter([
            'dossier' => $statement->getDossier()->getId(),
            'id' => $statement->getId(),
            ...$extra,
        ], static fn (int|string|null $value): bool => null !== $value && '' !== $value), Response::HTTP_SEE_OTHER);
    }
}
