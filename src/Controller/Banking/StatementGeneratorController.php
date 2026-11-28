<?php

declare(strict_types=1);

namespace App\Controller\Banking;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Security\Voter\DossierVoter;
use App\Service\Banking\BankingRefused;
use App\Service\Banking\Statement\BankStatements;
use App\Service\Banking\Statement\StatementGenerator;
use App\Service\Banking\Statement\StatementRow;
use App\Service\Dossier\Library\TemplateCard;
use App\Service\Dossier\Library\TemplateLibrary;
use App\Service\Shared\DateValue;
use App\Service\Shared\Money;
use App\Service\Shared\PostValue;
use App\Service\Shared\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Pièces › Générateur › Relevés (step 2.6b; design-ux.md § 2.4, « Générateur → relevés »): in a
 * template the teacher may change, a bank journal and the statement's days (the next statement's,
 * proposed), the movements of the account the books hold for them - each ticked, the teacher
 * unticks what the bank has not seen yet -, the bank's fees and agios, up to three transfers nobody
 * announced; « Générer le relevé » imports it (StatementGenerator) and opens its reconciliation in
 * the template. The page is a GET (journal and days); only « Générer » posts.
 */
#[Route('/teacher/documents/statements')]
final class StatementGeneratorController extends AbstractController
{
    private const int EXTRA_ROWS = 3;

    #[Route('', name: 'app_statement_generator', methods: ['GET'])]
    public function choose(Request $request, TemplateLibrary $library, #[CurrentUser] User $me): Response
    {
        $templates = $this->editableTemplates($library, $me);
        $id = QueryValue::int($request, 'dossier');
        foreach ($templates as $template) {
            if ($template->getId() === $id) {
                return $this->redirectToRoute('app_statement_generator_dossier', ['dossier' => $id]);
            }
        }

        return $this->render('banking/statement_generator.html.twig', [
            'dossier' => null,
            'template_options' => self::templateOptions($templates),
        ]);
    }

    #[Route('/{dossier}', name: 'app_statement_generator_dossier', requirements: ['dossier' => '\d+'], methods: ['GET', 'POST'])]
    public function generate(Dossier $dossier, Request $request, StatementGenerator $generator, BankStatements $statements, TemplateLibrary $library, #[CurrentUser] User $me): Response
    {
        if (!$dossier->isTemplate() || !$this->isGranted(DossierVoter::EDIT, $dossier)) {
            throw new NotFoundHttpException('Not a template of yours to change.');
        }
        $post = $request->isMethod('POST');

        $journals = $statements->bankJournals($dossier);
        $code = $post ? PostValue::trimmed($request, 'journal') : QueryValue::trimmed($request, 'journal');
        $journal = $journals[0] ?? null;
        foreach ($journals as $one) {
            if ($one->getCode() === $code) {
                $journal = $one;
            }
        }
        $defaults = null === $journal ? null : $statements->defaults($journal);
        $from = DateValue::parse($post ? PostValue::trimmed($request, 'from') : QueryValue::trimmed($request, 'from')) ?? $defaults['from'] ?? null;
        $to = DateValue::parse($post ? PostValue::trimmed($request, 'to') : QueryValue::trimmed($request, 'to')) ?? $defaults['to'] ?? null;
        $rows = null === $journal || null === $from || null === $to ? [] : $generator->rows($journal, $from, $to);
        $extras = [];
        $rowsPosted = $post ? PostValue::rows($request, 'extra') : [];
        for ($i = 0; $i < self::EXTRA_ROWS; ++$i) {
            $extra = $rowsPosted[$i] ?? [];
            $extras[] = ['date' => DateValue::parse($extra['date'] ?? ''), 'label' => $extra['label'] ?? '', 'amount' => self::cents($extra['amount'] ?? ''), 'error' => null];
        }
        $form = [
            'keep' => $post ? array_values(PostValue::stringsByKey($request, 'keep')) : array_map(static fn (StatementRow $row): string => $row->key, $rows),
            'fees' => self::cents($post ? PostValue::trimmed($request, 'fees') : ''),
            'interest' => self::cents($post ? PostValue::trimmed($request, 'interest') : ''),
            'extras' => $extras,
        ];
        $errors = [];
        $status = Response::HTTP_OK;
        if ($post && null !== $journal && null !== $from && null !== $to) {
            if (!$this->isCsrfTokenValid('statement-generator-'.$dossier->getId(), $request->request->getString('_token'))) {
                throw new NotFoundHttpException('Bad token.');
            }
            $fees = $form['fees'] ?? Money::zero();
            $interest = $form['interest'] ?? Money::zero();
            try {
                $statement = $generator->generate($journal, $from, $to, $form['keep'], $fees, $interest, $extras, $me);
                $this->addFlash('status', \sprintf('%s généré : %d opérations. L’étudiant le rapproche dans sa copie du modèle.', $statement->getTitle(), \count($statement->getLines())));

                return $this->redirectToRoute('app_bank_reconciliation_show', ['dossier' => $dossier->getId(), 'id' => $statement->getId()], Response::HTTP_SEE_OTHER);
            } catch (BankingRefused $refused) {
                $errors = $refused->errors + ['form' => $refused->getMessage()];
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return $this->render('banking/statement_generator.html.twig', [
            'dossier' => $dossier,
            'template_options' => self::templateOptions($this->editableTemplates($library, $me)),
            'journals' => $journals,
            'journal' => $journal,
            'from' => $from,
            'to' => $to,
            'statement_rows' => $rows,
            'form' => $form,
            'extra_rows' => self::EXTRA_ROWS,
            'errors' => $errors,
        ], new Response(status: $status));
    }

    /** An amount as Px:AmountInput posts it (cents, signed), null when left empty. */
    private static function cents(string $value): ?Money
    {
        return 1 === preg_match('/^-?\d{1,15}$/', $value) ? Money::cents((int) $value) : null;
    }

    /** @return list<Dossier> */
    private function editableTemplates(TemplateLibrary $library, User $me): array
    {
        return array_values(array_filter(
            array_map(static fn (TemplateCard $card): Dossier => $card->template, $library->cards($me)),
            fn (Dossier $template): bool => $this->isGranted(DossierVoter::EDIT, $template),
        ));
    }

    /**
     * @param list<Dossier> $templates
     *
     * @return list<array{value: int, label: string}>
     */
    private static function templateOptions(array $templates): array
    {
        return array_map(static fn (Dossier $template): array => ['value' => (int) $template->getId(), 'label' => $template->getLibraryTitle()], $templates);
    }
}
