<?php

declare(strict_types=1);

namespace App\Controller\Banking;

use App\Entity\Accounting\Journal;
use App\Entity\Banking\Payment;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Security\Voter\DossierRightVoter;
use App\Service\Accounting\Entry\AssistanceLevels;
use App\Service\Banking\BankingRefused;
use App\Service\Banking\Payment\Payments;
use App\Service\Banking\Proposal\PaymentProposals;
use App\Service\Banking\Proposal\ProposalRow;
use App\Service\Shared\Assistance\Assistance;
use App\Service\Shared\DateValue;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;
use App\Service\Shared\PostValue;
use App\Service\Shared\QueryValue;
use App\Service\Shared\ReturnPath;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Achats › Règlements › Proposition de paiement (step 2.7; design-ux.md § 3.2 « Règlements, Campagnes
 * de paiement », gabarit L then E): the suppliers' invoices still to pay due by a day (the dossier's
 * date by default), their « bon à payer » given or taken back on a selection (PURCHASES_VALIDATE), then
 * the transfer order of the approved ones ticked, shown before anything is written, and « Générer les
 * règlements » (PURCHASES_ENTER; the journal's right is the payments' door's). G ticks the approved
 * invoices due; G and B refuse a double payment, A signals it.
 */
#[Route('/d/{dossier}/payments/disbursements/proposal', requirements: ['dossier' => '\d+'])]
final class PaymentProposalController extends AbstractController
{
    #[Route('', name: 'app_payment_proposal', methods: ['GET'])]
    public function index(Dossier $dossier, Request $request, PaymentProposals $proposals, AssistanceLevels $levels): Response
    {
        $this->assertReads($dossier);
        $until = DateValue::parse(QueryValue::trimmed($request, 'until')) ?? $dossier->getDossierDate();
        $state = QueryValue::trimmed($request, 'state');
        $rows = $proposals->rows($dossier, $until);
        $shown = match ($state) {
            'to_approve' => array_values(array_filter($rows, static fn (ProposalRow $row): bool => !$row->isApproved())),
            'approved' => array_values(array_filter($rows, static fn (ProposalRow $row): bool => $row->isApproved())),
            default => $rows,
        };

        return $this->render('banking/proposal/index.html.twig', [
            'dossier' => $dossier,
            'until' => $until,
            'state' => \in_array($state, ['to_approve', 'approved'], true) ? $state : '',
            'proposal_rows' => $shown,
            'counts' => ['all' => \count($rows), 'to_approve' => \count(array_filter($rows, static fn (ProposalRow $row): bool => !$row->isApproved())), 'approved' => \count(array_filter($rows, static fn (ProposalRow $row): bool => $row->isApproved()))],
            'unmatched' => $proposals->unmatched($dossier),
            'propose' => Assistance::at($levels->for($dossier))->proposesPaymentSelection(),
            'can_approve' => $this->isGranted(DossierRightVoter::PURCHASES_VALIDATE, $dossier),
            'can_enter' => $this->isGranted(DossierRightVoter::PURCHASES_ENTER, $dossier),
            'here' => ReturnPath::here($request->getPathInfo(), (string) $request->getQueryString()),
        ]);
    }

    #[Route('/approve', name: 'app_payment_proposal_approve', methods: ['POST'])]
    public function approve(Dossier $dossier, Request $request, PaymentProposals $proposals, #[CurrentUser] User $me): Response
    {
        return $this->approval($dossier, $request, $proposals, $me, true);
    }

    #[Route('/withdraw', name: 'app_payment_proposal_withdraw', methods: ['POST'])]
    public function withdraw(Dossier $dossier, Request $request, PaymentProposals $proposals, #[CurrentUser] User $me): Response
    {
        return $this->approval($dossier, $request, $proposals, $me, false);
    }

    /** The transfer order of the invoices ticked: a GET, so that nothing is written before « Générer les règlements ». */
    #[Route('/order', name: 'app_payment_proposal_order', methods: ['GET'])]
    public function order(Dossier $dossier, Request $request, PaymentProposals $proposals, Payments $payments, AssistanceLevels $levels): Response
    {
        $this->assertReads($dossier);
        $ids = QueryValue::intList($request, 'invoices');

        return $this->renderOrder($dossier, $proposals, $payments, $levels, $ids, QueryValue::trimmed($request, 'journal'), DateValue::parse(QueryValue::trimmed($request, 'date')) ?? $dossier->getDossierDate(), QueryValue::trimmed($request, 'until'), []);
    }

    #[Route('/generate', name: 'app_payment_proposal_generate', methods: ['POST'])]
    public function generate(Dossier $dossier, Request $request, PaymentProposals $proposals, Payments $payments, AssistanceLevels $levels, #[CurrentUser] User $me): Response
    {
        $this->assertReads($dossier);
        $this->refuseBadToken($dossier, $request);
        if (!$this->isGranted(DossierRightVoter::PURCHASES_ENTER, $dossier)) {
            throw new NotFoundHttpException('Not a module this profile enters.');
        }
        $ids = PostValue::intList($request, 'invoices');
        $journal = PostValue::trimmed($request, 'journal');
        $date = DateValue::parse(PostValue::trimmed($request, 'date'));
        try {
            $outcome = $proposals->generate($dossier, $ids, $journal, $date, Assistance::at($levels->for($dossier)), $me);
        } catch (BankingRefused $refused) {
            return $this->renderOrder($dossier, $proposals, $payments, $levels, $ids, $journal, $date, PostValue::trimmed($request, 'until'), $refused->errors + ['order' => $refused->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $made = \count($outcome->payments);
        if ($made > 0) {
            $this->addFlash('status', \sprintf('%d virement%s enregistré%s, %s.', $made, $made > 1 ? 's' : '', $made > 1 ? 's' : '', FrenchFormat::amount(Money::sum(array_map(static fn (Payment $payment): Money => $payment->getAmount(), $outcome->payments)))));
        }
        foreach ($outcome->refused as $supplier => $why) {
            $this->addFlash('payment_fault', \sprintf('%s : %s', $supplier, $why));
        }

        return $this->redirectToRoute('app_payments', ['dossier' => $dossier->getId(), 'direction' => 'disbursements'], Response::HTTP_SEE_OTHER);
    }

    private function approval(Dossier $dossier, Request $request, PaymentProposals $proposals, User $me, bool $approve): Response
    {
        $this->assertReads($dossier);
        $this->refuseBadToken($dossier, $request);
        if (!$this->isGranted(DossierRightVoter::PURCHASES_VALIDATE, $dossier)) {
            throw new NotFoundHttpException('Not a module this profile validates.');
        }
        try {
            $count = $proposals->approve($dossier, PostValue::intList($request, 'invoices'), $approve, $me);
            $this->addFlash('status', 0 === $count
                ? ($approve ? 'Les factures cochées avaient déjà leur bon à payer.' : 'Aucune facture cochée n’avait de bon à payer.')
                : \sprintf('%s %s : %d facture%s.', $approve ? 'Bon à payer donné' : 'Bon à payer retiré', $count > 1 ? 'aux factures cochées' : 'à la facture cochée', $count, $count > 1 ? 's' : ''));
        } catch (BankingRefused $refused) {
            $this->addFlash('payment_fault', $refused->getMessage());
        }

        return $this->redirectToRoute('app_payment_proposal', array_filter(['dossier' => $dossier->getId(), 'until' => PostValue::trimmed($request, 'until'), 'state' => PostValue::trimmed($request, 'state')]), Response::HTTP_SEE_OTHER);
    }

    /**
     * @param list<int>             $ids
     * @param array<string, string> $errors
     */
    private function renderOrder(Dossier $dossier, PaymentProposals $proposals, Payments $payments, AssistanceLevels $levels, array $ids, string $journal, ?\DateTimeImmutable $date, string $until, array $errors, int $status = Response::HTTP_OK): Response
    {
        $journals = array_values(array_filter($payments->bankJournals($dossier), fn (Journal $one): bool => $this->isGranted(DossierRightVoter::JOURNAL_ENTER, $one)));
        if ('' === $journal && 1 === \count($journals)) {
            $journal = $journals[0]->getCode();
        }

        return $this->render('banking/proposal/order.html.twig', [
            'dossier' => $dossier,
            'order' => $proposals->order($dossier, $ids, Assistance::at($levels->for($dossier))),
            'journal_options' => array_map(static fn (Journal $one): array => ['value' => $one->getCode(), 'label' => $one->getCode().' · '.$one->getLabel()], $journals),
            'journal' => $journal,
            'date' => $date,
            'until' => $until,
            'errors' => $errors,
            'can_enter' => $this->isGranted(DossierRightVoter::PURCHASES_ENTER, $dossier),
        ], new Response(status: $status));
    }

    private function assertReads(Dossier $dossier): void
    {
        if (!$this->isGranted(DossierRightVoter::PURCHASES_READ, $dossier)) {
            throw new NotFoundHttpException('Not a module this profile reads.');
        }
    }

    private function refuseBadToken(Dossier $dossier, Request $request): void
    {
        if (!$this->isCsrfTokenValid('payment-proposal-'.$dossier->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Bad token.');
        }
    }
}
