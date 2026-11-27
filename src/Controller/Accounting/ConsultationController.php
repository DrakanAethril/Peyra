<?php

declare(strict_types=1);

namespace App\Controller\Accounting;

use App\Entity\Dossier\Dossier;
use App\Service\Accounting\Consultation\AccountStatement;
use App\Service\Accounting\Consultation\AccountStatements;
use App\Service\Accounting\Consultation\ConsultationWindow;
use App\Service\Accounting\Consultation\ConsultedSubject;
use App\Service\Accounting\Ledger\EntryScope;
use App\Service\Shared\Export\Column;
use App\Service\Shared\Export\ColumnKind;
use App\Service\Shared\Export\ExportFormat;
use App\Service\Shared\Export\ExportTable;
use App\Service\Shared\Export\TableExport;
use App\Service\Shared\ListPage;
use App\Service\Shared\QueryValue;
use App\Service\Shared\ReturnPath;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Comptabilité › Consultation › Compte ou tiers (lot 1, step 1.5; design-ux.md § 3.2, gabarit L):
 * the movements of an account or a third party over a window - the exercise up to the dossier's
 * date by default -, brouillard included or not, the balance carried at the head of each page
 * (§ 6.6), each line leading to its entry and the entry back here (`return`). Its export, and the
 * peek F5 opens from the entry grid (the account of the line: balance and last movements).
 */
#[Route('/d/{dossier}/accounting/consultation', requirements: ['dossier' => '\d+'])]
final class ConsultationController extends AbstractController
{
    private const int PEEK_MOVEMENTS = 8;

    #[Route('', name: 'app_accounting_consultation', methods: ['GET'])]
    public function index(Dossier $dossier, Request $request, AccountStatements $statements): Response
    {
        $reading = $this->read($dossier, $request, $statements);
        $statement = $reading['statement'];
        $page = ListPage::fromRequest($request, 'accounting.consultation')->within(null === $statement ? 0 : \count($statement->movements));

        return $this->render('accounting/consultation.html.twig', [
            'dossier' => $dossier,
            ...$reading,
            'list_page' => $page,
            'statement_page' => $statement?->page($page),
            'here' => ReturnPath::here($request->getPathInfo(), (string) $request->getQueryString()),
            'return_path' => ReturnPath::of(QueryValue::trimmed($request, 'return'), (int) $dossier->getId()),
        ], new Response(status: null !== $reading['error'] ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/export.{format}', name: 'app_accounting_consultation_export', requirements: ['format' => 'csv|xlsx'], methods: ['GET'])]
    public function export(Dossier $dossier, Request $request, string $format, AccountStatements $statements): Response
    {
        $statement = $this->read($dossier, $request, $statements)['statement'] ?? throw new NotFoundHttpException('Nothing to export.');
        $subject = $statement->subject;
        $table = new ExportTable(mb_substr($subject->code().' '.$subject->label(), 0, 31), [
            new Column('Date', ColumnKind::Day),
            new Column('Journal', ColumnKind::Code),
            new Column('Pièce', ColumnKind::Code, 16),
            new Column('Numéro', ColumnKind::Code, 18),
            new Column('Compte', ColumnKind::Code),
            new Column('Tiers', ColumnKind::Code),
            new Column('Libellé', width: 40),
            new Column('Échéance', ColumnKind::Day),
            new Column('Lettre', ColumnKind::Code, 8),
            new Column('Débit', ColumnKind::AmountCell),
            new Column('Crédit', ColumnKind::AmountCell),
            new Column('Solde (débit − crédit)', ColumnKind::Amount, 22),
        ]);
        if ($statement->hasOpening) {
            $table->add([$statement->openingDay(), null, null, null, null, null, 'Solde au '.$statement->openingDay()->format('d/m/Y'), null, null, $statement->opening->debit, $statement->opening->credit, $statement->opening->balance()]);
        }
        foreach ($statement->movements as $movement) {
            $line = $movement->line;
            $entry = $line->getEntry();
            $table->add([
                $entry->getEntryDate(), $entry->getJournal()->getCode(), $entry->getDocument(), $entry->getNumberLabel() ?? 'Brouillard',
                $line->getAccount()->getNumber(), $line->getThirdParty()?->getAuxiliaryCode() ?? $line->getThirdParty()?->getCode(),
                '' !== $line->getLabel() ? $line->getLabel() : $entry->getLabel(),
                $line->getDueDate(), $line->getLetter(), $line->getDebit(), $line->getCredit(), $movement->balanceAfter,
            ]);
        }

        return TableExport::response($table, ExportFormat::from($format), 'consultation-'.$subject->code().'-'.$dossier->getName());
    }

    /** F5 in the entry grid: the line's account or third party, its balance at the dossier's date and its last movements. */
    #[Route('/peek', name: 'app_accounting_consultation_peek', methods: ['GET'])]
    public function peek(Dossier $dossier, Request $request, AccountStatements $statements): Response
    {
        $typed = QueryValue::trimmed($request, 'account');
        $subject = $statements->subject($dossier, $typed);
        $statement = null === $subject ? null : $statements->statement($dossier, $subject, ConsultationWindow::of($statements->defaultFrom($dossier), $dossier->getDossierDate()), EntryScope::WithDrafts);

        return $this->render('accounting/_consultation_peek.html.twig', [
            'dossier' => $dossier,
            'typed' => mb_strtoupper($typed),
            'statement' => $statement,
            'last' => null === $statement ? [] : array_reverse(\array_slice($statement->movements, -self::PEEK_MOVEMENTS)),
            'return_path' => ReturnPath::of(QueryValue::trimmed($request, 'return'), (int) $dossier->getId()),
        ]);
    }

    /**
     * The screen's query read: the account or third party typed, the window, the scope.
     *
     * @return array{typed: string, subject: ?ConsultedSubject, statement: ?AccountStatement, window: ConsultationWindow, scope: EntryScope, default_from: \DateTimeImmutable, error: ?string}
     */
    private function read(Dossier $dossier, Request $request, AccountStatements $statements): array
    {
        $typed = mb_strtoupper(QueryValue::trimmed($request, 'account'));
        $scope = EntryScope::Validated->value === QueryValue::trimmed($request, 'scope') ? EntryScope::Validated : EntryScope::WithDrafts;
        $defaultFrom = $statements->defaultFrom($dossier);
        $window = ConsultationWindow::read(QueryValue::trimmed($request, 'from'), QueryValue::trimmed($request, 'to'), $defaultFrom, $dossier->getDossierDate());
        $subject = '' === $typed ? null : $statements->subject($dossier, $typed);
        $error = $window->error;
        if ('' !== $typed && null === $subject) {
            $error = \sprintf('Ni compte ni tiers ne répond à %s. F4 pour chercher.', $typed);
        }

        return [
            'typed' => $typed,
            'subject' => $subject,
            'statement' => null === $subject ? null : $statements->statement($dossier, $subject, $window, $scope),
            'window' => $window,
            'scope' => $scope,
            'default_from' => $defaultFrom,
            'error' => $error,
        ];
    }
}
