<?php

declare(strict_types=1);

namespace App\Controller\Banking;

use App\Entity\Accounting\Journal;
use App\Entity\Banking\Payment;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Banking\BillRemittance;
use App\Enum\Banking\PaymentDirection;
use App\Enum\Banking\PaymentState;
use App\Enum\ThirdParty\PaymentMethod;
use App\Repository\Banking\ChequeDepositRepository;
use App\Repository\Banking\PaymentRepository;
use App\Security\Voter\DossierRightVoter;
use App\Service\Accounting\Entry\AssistanceLevels;
use App\Service\Accounting\Lettering\LetteringRefused;
use App\Service\Banking\BankingRefused;
use App\Service\Banking\Payment\PaymentRequest;
use App\Service\Banking\Payment\Payments;
use App\Service\Shared\Assistance\Assistance;
use App\Service\Shared\DateValue;
use App\Service\Shared\FrenchFormat;
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
 * Ventes › Règlements and Achats › Règlements (step 2.6b; design-ux.md §§ 2.5 and 3.2): the receipts
 * and the disbursements of the dossier, a new payment (the customer or supplier first, then the
 * invoices it settles - in G the one whose amount or number it carries is ticked -), its fiche
 * (its entry, « Lettrer », a bill's remittance to the bank, « Supprimer »), and the remittance of
 * the cheques received. Payments is the one door; a receipt is read with the sales
 * (SALES_READ / SALES_ENTER), a disbursement with the purchases.
 */
#[Route('/d/{dossier}/payments', requirements: ['dossier' => '\d+'])]
final class PaymentsController extends AbstractController
{
    #[Route('/receipts/deposit', name: 'app_payment_deposit', methods: ['GET', 'POST'])]
    public function deposit(Dossier $dossier, Request $request, Payments $payments, PaymentRepository $repository, ChequeDepositRepository $deposits, #[CurrentUser] User $me): Response
    {
        $this->assertReads($dossier, PaymentDirection::Receipt);
        $journals = array_values(array_filter($payments->bankJournals($dossier), fn (Journal $journal): bool => $this->isGranted(DossierRightVoter::JOURNAL_READ, $journal)));
        $code = $request->isMethod('POST') ? PostValue::trimmed($request, 'journal') : QueryValue::trimmed($request, 'journal');
        $journal = $journals[0] ?? null;
        foreach ($journals as $one) {
            if ($one->getCode() === $code) {
                $journal = $one;
            }
        }
        $errors = [];
        $status = Response::HTTP_OK;
        $date = $dossier->getDossierDate();
        if ($request->isMethod('POST')) {
            $this->refuseBadToken($dossier, $request);
            $this->assertEnters($dossier, PaymentDirection::Receipt);
            $date = DateValue::parse(PostValue::trimmed($request, 'date'));
            try {
                $deposit = $payments->deposit($journal ?? throw new NotFoundHttpException('No bank journal.'), PostValue::intList($request, 'cheques'), $date, $me);
                $this->addFlash('status', \sprintf('%s enregistrée au journal %s.', $deposit->getReference(), $deposit->getJournal()->getCode()));

                return $this->redirectToRoute('app_payment_deposit', ['dossier' => $dossier->getId(), 'journal' => $deposit->getJournal()->getCode()], Response::HTTP_SEE_OTHER);
            } catch (BankingRefused $refused) {
                $errors = $refused->errors + ['cheques' => $refused->getMessage()];
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            }
        }

        return $this->render('banking/cheque_deposit.html.twig', [
            'dossier' => $dossier,
            'journals' => $journals,
            'journal' => $journal,
            'cheques' => null === $journal ? [] : $repository->findChequesToDeposit($journal),
            'deposits' => $deposits->findLatestFirst($dossier),
            'chosen' => $request->isMethod('POST') ? PostValue::intList($request, 'cheques') : null,
            'date' => $date,
            'errors' => $errors,
            'can_enter' => $this->isGranted(DossierRightVoter::SALES_ENTER, $dossier),
        ] + $this->layout(PaymentDirection::Receipt), new Response(status: $status));
    }

    #[Route('/{direction}', name: 'app_payments', requirements: ['direction' => 'receipts|disbursements'], methods: ['GET'])]
    public function index(Dossier $dossier, string $direction, Request $request, PaymentRepository $payments): Response
    {
        $way = $this->direction($direction);
        $this->assertReads($dossier, $way);
        $method = PaymentMethod::tryFrom(QueryValue::trimmed($request, 'method'));

        return $this->render('banking/payments.html.twig', [
            'dossier' => $dossier,
            'payments' => $payments->findLatestFirst($dossier, $way, $method),
            'method' => $method,
            'methods' => Payments::methods($way),
            'can_enter' => $this->isGranted($this->enterRight($way), $dossier),
        ] + $this->layout($way));
    }

    #[Route('/{direction}/new', name: 'app_payment_new', requirements: ['direction' => 'receipts|disbursements'], methods: ['GET', 'POST'])]
    public function new(Dossier $dossier, string $direction, Request $request, Payments $payments, AssistanceLevels $assistance, #[CurrentUser] User $me): Response
    {
        $way = $this->direction($direction);
        $this->assertEnters($dossier, $way);
        $parties = $payments->parties($dossier, $way);
        $post = $request->isMethod('POST');
        $code = $post ? PostValue::trimmed($request, 'thirdParty') : QueryValue::trimmed($request, 'party');
        $party = null;
        foreach ($parties as $one) {
            if ($one->getCode() === $code) {
                $party = $one;
            }
        }
        $journals = $this->journalOptions($dossier, $payments);
        $form = new PaymentRequest($way, $party?->getPaymentMethod(), $party?->getCode() ?? '', '', $dossier->getDossierDate());
        if (null !== $form->method && !\in_array($form->method, Payments::methods($way), true)) {
            $form->method = null;
        }
        $errors = [];
        $status = Response::HTTP_OK;
        if ($post) {
            $this->refuseBadToken($dossier, $request);
            $cents = PostValue::cents($request, 'amount');
            $form = new PaymentRequest(
                $way,
                PaymentMethod::tryFrom(PostValue::trimmed($request, 'method')),
                $code,
                PostValue::trimmed($request, 'journal'),
                DateValue::parse(PostValue::trimmed($request, 'date')),
                PostValue::trimmed($request, 'reference'),
                null === $cents ? null : Money::cents($cents),
                DateValue::parse(PostValue::trimmed($request, 'dueOn')),
                PostValue::intList($request, 'invoices'),
            );
            try {
                $payment = $payments->record($dossier, $form, $me);
                $this->addFlash('status', \sprintf('%s %s enregistré.', $way->label(), $payment->getReference()));

                return $this->redirectToRoute('app_payment', ['dossier' => $dossier->getId(), 'direction' => $direction, 'id' => $payment->getId()], Response::HTTP_SEE_OTHER);
            } catch (BankingRefused $refused) {
                $errors = $refused->errors;
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            }
        }

        return $this->render('banking/payment_new.html.twig', [
            'dossier' => $dossier,
            'party' => $party,
            'party_options' => array_map(static fn (ThirdParty $one): array => ['value' => $one->getCode(), 'label' => $one->getCode().' · '.$one->getName()], $parties),
            'invoices' => null === $party ? [] : $payments->openInvoices($party, $way),
            'method_options' => array_map(static fn (PaymentMethod $one): array => ['value' => $one->value, 'label' => $one->label()], Payments::methods($way)),
            'journal_options' => $journals,
            'form' => $form,
            'errors' => $errors,
            'propose' => Assistance::at($assistance->for($dossier))->proposesPaymentAllocation(),
        ] + $this->layout($way), new Response(status: $status));
    }

    #[Route('/{direction}/{id}', name: 'app_payment', requirements: ['direction' => 'receipts|disbursements', 'id' => '\d+'], methods: ['GET'])]
    public function show(Dossier $dossier, string $direction, int $id, PaymentRepository $repository, Payments $payments): Response
    {
        $way = $this->direction($direction);
        $payment = $this->payment($dossier, $way, $id, $repository);

        return $this->render('banking/payment.html.twig', [
            'dossier' => $dossier,
            'payment' => $payment,
            'lettering_refusal' => $payments->letteringRefusal($payment),
            'can_enter' => $this->isGranted($this->enterRight($way), $dossier) && $this->isGranted(DossierRightVoter::JOURNAL_ENTER, $payment->getJournal()),
            'bank_journals' => $payment->isBill() && PaymentState::InPortfolio === $payment->state() ? $payments->bankJournals($dossier) : [],
            'remittance' => ['kind' => BillRemittance::Collection->value, 'journal' => '', 'date' => $dossier->getDossierDate(), 'interest' => Money::zero(), 'commission' => Money::zero(), 'commissionVat' => Money::zero()],
            'errors' => [],
        ] + $this->layout($way));
    }

    #[Route('/{direction}/{id}/letter', name: 'app_payment_letter', requirements: ['direction' => 'receipts|disbursements', 'id' => '\d+'], methods: ['POST'])]
    public function letter(Dossier $dossier, string $direction, int $id, Request $request, PaymentRepository $repository, Payments $payments, #[CurrentUser] User $me): Response
    {
        $way = $this->direction($direction);
        $payment = $this->payment($dossier, $way, $id, $repository);
        $this->refuseBadToken($dossier, $request);
        $this->assertEnters($dossier, $way);
        try {
            $result = $payments->letter($payment, $me);
            $this->addFlash('status', \sprintf('Règlement lettré avec %s : lettre %s%s.', 1 === \count($payment->getAllocations()) ? 'sa facture' : 'ses factures', $result->letter, $result->isPartial() ? ', partiel' : ''));
        } catch (BankingRefused|LetteringRefused $refused) {
            $this->addFlash('payment_fault', $refused->getMessage());
        }

        return $this->redirectToRoute('app_payment', ['dossier' => $dossier->getId(), 'direction' => $direction, 'id' => $id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/receipts/{id}/remit', name: 'app_payment_remit', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function remit(Dossier $dossier, int $id, Request $request, PaymentRepository $repository, Payments $payments, #[CurrentUser] User $me): Response
    {
        $payment = $this->payment($dossier, PaymentDirection::Receipt, $id, $repository);
        $this->refuseBadToken($dossier, $request);
        $this->assertEnters($dossier, PaymentDirection::Receipt);
        $journals = $payments->bankJournals($dossier);
        $code = PostValue::trimmed($request, 'journal');
        $journal = null;
        foreach ($journals as $one) {
            if ($one->getCode() === $code) {
                $journal = $one;
            }
        }
        $kind = BillRemittance::tryFrom(PostValue::trimmed($request, 'kind')) ?? BillRemittance::Collection;
        $form = [
            'kind' => $kind->value,
            'journal' => $code,
            'date' => DateValue::parse(PostValue::trimmed($request, 'date')),
            'interest' => Money::cents(PostValue::cents($request, 'interest') ?? 0),
            'commission' => Money::cents(PostValue::cents($request, 'commission') ?? 0),
            'commissionVat' => Money::cents(PostValue::cents($request, 'commissionVat') ?? 0),
        ];
        $errors = [];
        if (null === $journal) {
            $errors['journal'] = 'Choisissez le journal de la banque.';
        } else {
            try {
                $payments->remit($payment, $kind, $journal, $form['date'], $form['interest'], $form['commission'], $form['commissionVat'], $me);
                $this->addFlash('status', \sprintf('Effet %s %s.', $payment->getReference(), mb_strtolower($kind->label())));

                return $this->redirectToRoute('app_payment', ['dossier' => $dossier->getId(), 'direction' => 'receipts', 'id' => $id], Response::HTTP_SEE_OTHER);
            } catch (BankingRefused $refused) {
                $errors = $refused->errors + ['remit' => $refused->getMessage()];
            }
        }

        return $this->render('banking/payment.html.twig', [
            'dossier' => $dossier,
            'payment' => $payment,
            'lettering_refusal' => $payments->letteringRefusal($payment),
            'can_enter' => true,
            'bank_journals' => $journals,
            'remittance' => $form,
            'errors' => $errors,
        ] + $this->layout(PaymentDirection::Receipt), new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/{direction}/{id}/delete', name: 'app_payment_delete', requirements: ['direction' => 'receipts|disbursements', 'id' => '\d+'], methods: ['POST'])]
    public function delete(Dossier $dossier, string $direction, int $id, Request $request, PaymentRepository $repository, Payments $payments, #[CurrentUser] User $me): Response
    {
        $way = $this->direction($direction);
        $payment = $this->payment($dossier, $way, $id, $repository);
        $this->refuseBadToken($dossier, $request);
        $this->assertEnters($dossier, $way);
        $reference = $payment->getReference();
        try {
            $payments->delete($payment, $me);
            $this->addFlash('status', \sprintf('Règlement %s supprimé (%s).', $reference, FrenchFormat::amount($payment->getAmount())));
        } catch (BankingRefused $refused) {
            $this->addFlash('payment_fault', $refused->getMessage());

            return $this->redirectToRoute('app_payment', ['dossier' => $dossier->getId(), 'direction' => $direction, 'id' => $id], Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute('app_payments', ['dossier' => $dossier->getId(), 'direction' => $direction], Response::HTTP_SEE_OTHER);
    }

    private function direction(string $slug): PaymentDirection
    {
        return PaymentDirection::fromSlug($slug) ?? throw new NotFoundHttpException('No such direction.');
    }

    private function assertReads(Dossier $dossier, PaymentDirection $direction): void
    {
        if (!$this->isGranted(PaymentDirection::Receipt === $direction ? DossierRightVoter::SALES_READ : DossierRightVoter::PURCHASES_READ, $dossier)) {
            throw new NotFoundHttpException('Not a module this profile reads.');
        }
    }

    private function assertEnters(Dossier $dossier, PaymentDirection $direction): void
    {
        if (!$this->isGranted($this->enterRight($direction), $dossier)) {
            throw new NotFoundHttpException('Not a module this profile enters.');
        }
    }

    private function enterRight(PaymentDirection $direction): string
    {
        return PaymentDirection::Receipt === $direction ? DossierRightVoter::SALES_ENTER : DossierRightVoter::PURCHASES_ENTER;
    }

    private function payment(Dossier $dossier, PaymentDirection $direction, int $id, PaymentRepository $repository): Payment
    {
        $this->assertReads($dossier, $direction);
        $payment = $repository->find($id);
        if (null === $payment || $payment->getDossier() !== $dossier || $payment->getDirection() !== $direction) {
            throw new NotFoundHttpException('No such payment.');
        }

        return $payment;
    }

    private function refuseBadToken(Dossier $dossier, Request $request): void
    {
        if (!$this->isCsrfTokenValid('payment-'.$dossier->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Bad token.');
        }
    }

    /** @return list<array{value: string, label: string}> the bank, cash and general journals, which a method narrows */
    private function journalOptions(Dossier $dossier, Payments $payments): array
    {
        $options = [];
        foreach ([PaymentMethod::Transfer, PaymentMethod::Cash, PaymentMethod::Bill] as $method) {
            foreach ($payments->journalsFor($dossier, $method) as $journal) {
                $options[] = ['value' => $journal->getCode(), 'label' => \sprintf('%s · %s (%s)', $journal->getCode(), $journal->getLabel(), match ($method) {
                    PaymentMethod::Cash => 'caisse',
                    PaymentMethod::Bill => 'effets',
                    default => 'banque',
                })];
            }
        }

        return $options;
    }

    /**
     * The module the screen sits in: Ventes for a receipt, Achats et stocks for a disbursement.
     *
     * @return array{direction: PaymentDirection, payment_layout: string, slug: string}
     */
    private function layout(PaymentDirection $direction): array
    {
        return [
            'direction' => $direction,
            'payment_layout' => PaymentDirection::Receipt === $direction ? 'sales/_layout.html.twig' : 'inventory/_layout.html.twig',
            'slug' => $direction->slug(),
        ];
    }
}
