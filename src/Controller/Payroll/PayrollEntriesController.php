<?php

declare(strict_types=1);

namespace App\Controller\Payroll;

use App\Entity\Accounting\Journal;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Accounting\AccountingBehaviour;
use App\Security\Voter\DossierRightVoter;
use App\Service\Document\Processing\PieceCheck;
use App\Service\Payroll\Statement\PayrollEntries;
use App\Service\Payroll\Statement\PayrollMonthStatements;
use App\Service\Payroll\Statement\PayrollPosting;
use App\Service\Payroll\Statement\PayrollStatementsReader;
use App\Service\Shared\PostValue;
use App\Service\Shared\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Paie et social › Écritures de paie (step 4.5; design-ux.md § 3.2, gabarit E): the entry of a month of
 * pay from its validated payslips (PayrollEntries) and the payments it leaves - the net salaries, each
 * body's contributions -, each through the accounting gateway as the dossier's behaviour says
 * (PayrollPosting): its entries once written, else the proposal and « Passer au brouillard », else (« Laisser
 * saisir ») the journal to enter it in. Read with PAYROLL_READ, posted with PAYROLL_ENTER.
 */
#[Route('/d/{dossier}/payroll/entries', requirements: ['dossier' => '\d+'])]
final class PayrollEntriesController extends AbstractController
{
    #[Route('', name: 'app_payroll_entries', methods: ['GET'])]
    public function index(Dossier $dossier, Request $request, PayrollStatementsReader $reader, PayrollPosting $posting): Response
    {
        if (!$this->isGranted(DossierRightVoter::PAYROLL_READ, $dossier)) {
            throw new NotFoundHttpException('The payroll is closed to this profile.');
        }
        $months = $reader->months($dossier);
        $month = self::month($months, QueryValue::trimmed($request, 'month'));
        $statements = null === $month ? null : $reader->of($dossier, $month);
        $banks = $posting->bankJournals($dossier);
        $bank = self::bank($banks, QueryValue::trimmed($request, 'bank'));
        $behaviour = $posting->behaviour($dossier);

        return $this->render('payroll/entries.html.twig', [
            'dossier' => $dossier,
            'months' => $months,
            'month' => $month,
            'statements' => $statements,
            'behaviour' => $behaviour,
            'journal' => $posting->journal($dossier),
            'banks' => $banks,
            'bank' => $bank,
            'closed' => null !== $month && $posting->isClosed($dossier, $month),
            'month_entry' => null === $statements ? null : $this->monthEntry($dossier, $statements, $posting, $behaviour),
            'payments' => null === $statements ? [] : $this->payments($dossier, $statements, $posting, $behaviour, $bank),
        ]);
    }

    #[Route('/post', name: 'app_payroll_entries_post', methods: ['POST'])]
    public function post(Dossier $dossier, Request $request, PayrollStatementsReader $reader, PayrollPosting $posting, #[CurrentUser] User $me): Response
    {
        $statements = $this->gesture($dossier, $request, $reader, 'payroll-post-');
        $document = PayrollEntries::monthDocument($statements->month);
        if (!$posting->isClosed($dossier, $statements->month) || AccountingBehaviour::LetEnter === $posting->behaviour($dossier) || [] !== $posting->standing($dossier, $document)) {
            throw new NotFoundHttpException('This month’s entry is not posted from here.');
        }
        $this->flashed($posting->post($dossier, $statements, $me));

        return $this->back($dossier, $statements, $request);
    }

    #[Route('/pay', name: 'app_payroll_entries_pay', methods: ['POST'])]
    public function pay(Dossier $dossier, Request $request, PayrollStatementsReader $reader, PayrollPosting $posting, #[CurrentUser] User $me): Response
    {
        $statements = $this->gesture($dossier, $request, $reader, 'payroll-pay-');
        $code = PostValue::trimmed($request, 'bank');
        $bank = array_values(array_filter($posting->bankJournals($dossier), static fn (Journal $journal): bool => $journal->getCode() === $code))[0] ?? throw new NotFoundHttpException('No such bank journal.');
        $key = PostValue::trimmed($request, 'payment');
        $payment = null;
        foreach ($posting->payments($statements) as $candidate) {
            if ($candidate['key'] === $key) {
                $payment = $candidate;
            }
        }
        if (null === $payment || AccountingBehaviour::LetEnter === $posting->behaviour($dossier) || [] !== $posting->standing($dossier, $payment['document'])) {
            throw new NotFoundHttpException('This payment is not posted from here.');
        }
        $this->flashed($posting->pay($dossier, $payment, $bank, $me));

        return $this->back($dossier, $statements, $request, $bank);
    }

    /**
     * The card of the month's entry: its pièce, the entries written, and - its month closed, none standing, not under « Laisser saisir » - the proposal and its check.
     *
     * @return array<string, mixed>
     */
    private function monthEntry(Dossier $dossier, PayrollMonthStatements $statements, PayrollPosting $posting, AccountingBehaviour $behaviour): array
    {
        $document = PayrollEntries::monthDocument($statements->month);
        $standing = $posting->standing($dossier, $document);
        $proposal = null;
        $check = null;
        if ([] === $standing && AccountingBehaviour::LetEnter !== $behaviour) {
            $proposal = $posting->proposal($dossier, $statements);
            $check = null === $proposal ? null : $posting->check($dossier, $proposal);
        }

        return ['document' => $document, 'entries' => $posting->entries($dossier, $document), 'standing' => $standing, 'proposal' => $proposal, 'check' => $check];
    }

    /**
     * Each payment of the month: what it settles, its pièce, its entries, and its proposal in the bank journal chosen.
     *
     * @return list<array<string, mixed>>
     */
    private function payments(Dossier $dossier, PayrollMonthStatements $statements, PayrollPosting $posting, AccountingBehaviour $behaviour, ?Journal $bank): array
    {
        $cards = [];
        foreach ($posting->payments($statements) as $payment) {
            $standing = $posting->standing($dossier, $payment['document']);
            $proposal = [] === $standing && AccountingBehaviour::LetEnter !== $behaviour && null !== $bank ? $posting->paymentProposal($dossier, $payment, $bank) : null;
            $cards[] = [...$payment, 'entries' => $posting->entries($dossier, $payment['document']), 'standing' => $standing, 'proposal' => $proposal, 'check' => null === $proposal ? null : $posting->check($dossier, $proposal)];
        }

        return $cards;
    }

    private function gesture(Dossier $dossier, Request $request, PayrollStatementsReader $reader, string $tokenPrefix): PayrollMonthStatements
    {
        if (!$this->isGranted(DossierRightVoter::PAYROLL_ENTER, $dossier) || !$this->isCsrfTokenValid($tokenPrefix.$dossier->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not yours to post.');
        }
        $month = self::month($reader->months($dossier), PostValue::trimmed($request, 'month'));
        if (null === $month || $month->format('Y-m') !== PostValue::trimmed($request, 'month')) {
            throw new NotFoundHttpException('No validated payslip that month.');
        }

        return $reader->of($dossier, $month);
    }

    private function flashed(PieceCheck $check): void
    {
        $this->addFlash('status', $check->posted
            ? 'L’écriture est au brouillard : vérifiez-la, corrigez-la au besoin, puis validez-la.'
            : 'L’écriture n’est pas passée : '.implode(' ', array_unique(array_values($check->errors))));
    }

    private function back(Dossier $dossier, PayrollMonthStatements $statements, Request $request, ?Journal $bank = null): Response
    {
        return $this->redirectToRoute('app_payroll_entries', array_filter(['dossier' => $dossier->getId(), 'month' => $statements->month->format('Y-m'), 'bank' => $bank?->getCode()]), Response::HTTP_SEE_OTHER);
    }

    /** @param list<\DateTimeImmutable> $months the latest first */
    private static function month(array $months, string $asked): ?\DateTimeImmutable
    {
        foreach ($months as $month) {
            if ($month->format('Y-m') === $asked) {
                return $month;
            }
        }

        return $months[0] ?? null;
    }

    /** @param list<Journal> $banks */
    private static function bank(array $banks, string $code): ?Journal
    {
        foreach ($banks as $bank) {
            if ($bank->getCode() === $code) {
                return $bank;
            }
        }

        return $banks[0] ?? null;
    }
}
