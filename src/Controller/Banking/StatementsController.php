<?php

declare(strict_types=1);

namespace App\Controller\Banking;

use App\Entity\Accounting\Journal;
use App\Entity\Banking\BankStatementLine;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Repository\Banking\BankStatementRepository;
use App\Security\Voter\DossierRightVoter;
use App\Service\Accounting\Entry\AssistanceLevels;
use App\Service\Banking\BankingRefused;
use App\Service\Banking\Reconciliation\ReconciliationBoard;
use App\Service\Banking\Statement\BankStatements;
use App\Service\Banking\Statement\StatementCsv;
use App\Service\Shared\Assistance\Assistance;
use App\Service\Shared\DateValue;
use App\Service\Shared\Money;
use App\Service\Shared\PostValue;
use App\Service\Shared\QueryValue;
use App\Service\Shared\Storage\UploadPolicy;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Banque › Relevés (step 2.6a; design-ux.md § 3.2, « Relevés » and « Import d'un relevé »): the
 * statements of the bank journals the person reads, the latest first, each with its period, its
 * closing balance and where its reconciliation stands; « Importer un relevé » reads a CSV file
 * whole (BankStatements); a statement is exported as CSV (the Pennylane session) and deleted while
 * nothing of it is pointed.
 */
#[Route('/d/{dossier}/bank/statements', requirements: ['dossier' => '\d+'])]
final class StatementsController extends AbstractController
{
    use BankingControllerTrait;

    #[Route('', name: 'app_bank_statements', methods: ['GET'])]
    public function index(Dossier $dossier, Request $request, BankStatements $bank, BankStatementRepository $statements, ReconciliationBoard $board, AssistanceLevels $assistance): Response
    {
        $journals = $this->readableJournals($dossier, $bank);
        $code = QueryValue::trimmed($request, 'journal');
        $journal = null;
        foreach ($journals as $one) {
            if ($one->getCode() === $code) {
                $journal = $one;
            }
        }
        $level = Assistance::at($assistance->for($dossier));
        $rows = [];
        foreach ($statements->findLatestFirst($dossier, $journal) as $statement) {
            if (\in_array($statement->getJournal(), $journals, true)) {
                $rows[] = ['statement' => $statement, 'status' => $board->view($statement, $level)->status()];
            }
        }

        return $this->render('banking/statements.html.twig', [
            'dossier' => $dossier,
            'journals' => $journals,
            'journal' => $journal,
            'statement_rows' => $rows,
        ]);
    }

    #[Route('/import', name: 'app_bank_statement_import', methods: ['GET', 'POST'])]
    public function import(Dossier $dossier, Request $request, BankStatements $bank, #[CurrentUser] User $me): Response
    {
        $journals = array_values(array_filter($this->readableJournals($dossier, $bank), fn (Journal $journal): bool => $this->isGranted(DossierRightVoter::JOURNAL_ENTER, $journal)));
        if ([] === $journals) {
            throw new NotFoundHttpException('No bank journal to import into.');
        }
        $code = $request->isMethod('POST') ? PostValue::trimmed($request, 'journal') : QueryValue::trimmed($request, 'journal');
        $journal = $journals[0];
        foreach ($journals as $one) {
            if ($one->getCode() === $code) {
                $journal = $one;
            }
        }
        $defaults = $bank->defaults($journal);
        $form = ['number' => (string) $defaults['number'], 'from' => $defaults['from'], 'to' => $defaults['to'], 'opening' => $defaults['opening']];
        $errors = [];
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            $this->refuseBadToken($dossier, $request);
            $form = [
                'number' => PostValue::trimmed($request, 'number'),
                'from' => DateValue::parse(PostValue::trimmed($request, 'from')),
                'to' => DateValue::parse(PostValue::trimmed($request, 'to')),
                'opening' => Money::cents(PostValue::cents($request, 'opening') ?? 0),
            ];
            $file = $request->files->get('file');
            $policy = self::policy();
            if (!$file instanceof UploadedFile) {
                $errors['file'] = 'Choisissez le fichier CSV du relevé.';
            } elseif (null !== ($refusal = $policy->refusalOf($file))) {
                $errors['file'] = $refusal;
            } else {
                try {
                    $statement = $bank->import($journal, 1 === preg_match('/^\d{1,6}$/', $form['number']) ? (int) $form['number'] : 0, $form['from'], $form['to'], $form['opening'], (string) file_get_contents($file->getPathname()), $file->getClientOriginalName(), $me);
                    $this->addFlash('status', \sprintf('%s importé : %d opérations.', $statement->getTitle(), \count($statement->getLines())));

                    return $this->redirectToRoute('app_bank_reconciliation_show', ['dossier' => $dossier->getId(), 'id' => $statement->getId()], Response::HTTP_SEE_OTHER);
                } catch (BankingRefused $refused) {
                    $errors = $refused->errors;
                }
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return $this->render('banking/statement_import.html.twig', [
            'dossier' => $dossier,
            'journals' => $journals,
            'journal' => $journal,
            'form' => $form,
            'errors' => $errors,
            'line_errors' => array_filter($errors, static fn (string $key): bool => str_starts_with($key, 'line.'), \ARRAY_FILTER_USE_KEY),
            'accept' => self::policy()->accept(),
        ], new Response(status: $status));
    }

    #[Route('/{id}/export.csv', name: 'app_bank_statement_export', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function export(Dossier $dossier, int $id, BankStatementRepository $statements): Response
    {
        $statement = $this->readableStatement($dossier, $id, $statements);
        $csv = StatementCsv::write(array_map(static fn (BankStatementLine $line): array => ['date' => $line->getDate(), 'label' => $line->getLabel(), 'amount' => $line->getAmount()], $statement->getLines()));
        $name = \sprintf('releve-%s-%02d.csv', mb_strtolower($statement->getJournal()->getCode()), $statement->getNumber());

        return new Response($csv, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }

    #[Route('/{id}/delete', name: 'app_bank_statement_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Dossier $dossier, int $id, Request $request, BankStatementRepository $statements, BankStatements $bank, #[CurrentUser] User $me): Response
    {
        $statement = $this->readableStatement($dossier, $id, $statements);
        $this->refuseBadToken($dossier, $request);
        if (!$this->isGranted(DossierRightVoter::JOURNAL_ENTER, $statement->getJournal())) {
            throw new NotFoundHttpException('Not yours to delete.');
        }
        $title = $statement->getTitle();
        try {
            $bank->delete($statement, $me);
            $this->addFlash('status', $title.' supprimé.');
        } catch (BankingRefused $refused) {
            $this->addFlash('bank_fault', $refused->getMessage());

            return $this->redirectToRoute('app_bank_reconciliation_show', ['dossier' => $dossier->getId(), 'id' => $id], Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute('app_bank_statements', ['dossier' => $dossier->getId()], Response::HTTP_SEE_OTHER);
    }

    public static function policy(): UploadPolicy
    {
        return UploadPolicy::platform()->restrictTo('csv', 'txt')->limitedTo(2 * 1024 * 1024);
    }

    /** @return list<Journal> */
    private function readableJournals(Dossier $dossier, BankStatements $bank): array
    {
        return array_values(array_filter($bank->bankJournals($dossier), fn (Journal $journal): bool => $this->isGranted(DossierRightVoter::JOURNAL_READ, $journal)));
    }
}
