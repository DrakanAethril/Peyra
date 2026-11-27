<?php

declare(strict_types=1);

namespace App\Controller\Accounting;

use App\Entity\Accounting\Account;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Accounting\AccountNature;
use App\Repository\Accounting\AccountRepository;
use App\Repository\Accounting\JournalRepository;
use App\Security\Voter\DossierVoter;
use App\Service\Accounting\Chart\AccountDraft;
use App\Service\Accounting\Chart\AccountNumber;
use App\Service\Accounting\Chart\AccountSheet;
use App\Service\Accounting\Chart\ReferenceCharts;
use App\Service\Shared\PostValue;
use App\Service\Shared\QueryValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The fiche of an account (design-ux.md § 3.2, « Plan de comptes », gabarit F): number, label,
 * lettrable, collective; its class, nature and the reference account it subdivides as facts. F10
 * saves for whoever works in the dossier (DossierVoter::EDIT), anybody else reads; an unused account
 * leaves the plan by a two-step button. The address is the account's number.
 */
#[Route('/d/{dossier}/accounting/accounts', requirements: ['dossier' => '\d+'])]
final class AccountController extends AbstractController
{
    #[Route('/new', name: 'app_accounting_account_new', methods: ['GET', 'POST'])]
    public function create(Dossier $dossier, Request $request, AccountSheet $sheet, ReferenceCharts $charts, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        if (!$this->isGranted(DossierVoter::EDIT, $dossier)) {
            throw new NotFoundHttpException('Not yours to change.');
        }
        $draft = new AccountDraft();
        $draft->number = AccountNumber::normalize(QueryValue::trimmed($request, 'number'));
        if (null === AccountNumber::fault($draft->number)) {
            // A number given in the address (« Créer le compte 411500 ») ticks its defaults.
            $draft->lettrable = AccountNumber::isLettrableByDefault($draft->number);
            $draft->collective = AccountNumber::isCollectiveByDefault($draft->number);
        }
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            $this->read($request, $dossier, $draft);
            if ($sheet->check($draft, $dossier)) {
                $sheet->create($dossier, $draft, $me);
                $entityManager->flush();
                $this->addFlash('status', \sprintf('Compte %s créé.', $draft->number).(null !== $draft->warning ? ' '.$draft->warning : ''));

                return $this->redirectToRoute('app_accounting_account', ['dossier' => $dossier->getId(), 'number' => $draft->number], Response::HTTP_SEE_OTHER);
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return $this->render('accounting/account.html.twig', $this->view($dossier, $charts, $draft, null, null), new Response(status: $status));
    }

    #[Route('/{number}', name: 'app_accounting_account', requirements: ['number' => '\d{6,8}'], methods: ['GET', 'POST'])]
    public function edit(Dossier $dossier, string $number, Request $request, AccountRepository $accounts, AccountSheet $sheet, ReferenceCharts $charts, JournalRepository $journals, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $account = $accounts->findOneByNumber($dossier, $number) ?? throw new NotFoundHttpException('No such account.');
        $draft = AccountDraft::of($account);
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            if (!$this->isGranted(DossierVoter::EDIT, $dossier)) {
                throw new NotFoundHttpException('Not yours to change.');
            }
            $this->read($request, $dossier, $draft, 'dossier-account-'.$account->getId());
            if ($sheet->check($draft, $dossier, $account)) {
                $changed = $sheet->update($account, $draft, $me);
                $entityManager->flush();
                $this->addFlash('status', $changed ? \sprintf('Compte %s enregistré.', $draft->number).(null !== $draft->warning ? ' '.$draft->warning : '') : 'Rien à enregistrer : le compte est inchangé.');

                return $this->redirectToRoute('app_accounting_account', ['dossier' => $dossier->getId(), 'number' => $draft->number], Response::HTTP_SEE_OTHER);
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        } else {
            $draft->warning = $sheet->outsideChart($dossier, $account->getNumber());
        }

        return $this->render('accounting/account.html.twig', $this->view($dossier, $charts, $draft, $account, $sheet->deletionRefusal($account), $journals->findByCounterpart($account)), new Response(status: $status));
    }

    #[Route('/{number}/delete', name: 'app_accounting_account_delete', requirements: ['number' => '\d{6,8}'], methods: ['POST'])]
    public function delete(Dossier $dossier, string $number, Request $request, AccountRepository $accounts, AccountSheet $sheet, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $account = $accounts->findOneByNumber($dossier, $number) ?? throw new NotFoundHttpException('No such account.');
        if (!$this->isGranted(DossierVoter::EDIT, $dossier) || !$this->isCsrfTokenValid('dossier-account-delete-'.$account->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not yours to change.');
        }
        $refusal = $sheet->deletionRefusal($account);
        if (null !== $refusal) {
            $this->addFlash('status', $refusal);

            return $this->redirectToRoute('app_accounting_account', ['dossier' => $dossier->getId(), 'number' => $number], Response::HTTP_SEE_OTHER);
        }
        $sheet->delete($account, $me);
        $entityManager->flush();
        $this->addFlash('status', \sprintf('Compte %s supprimé du plan.', $number));

        return $this->redirectToRoute('app_accounting_accounts', ['dossier' => $dossier->getId()], Response::HTTP_SEE_OTHER);
    }

    private function read(Request $request, Dossier $dossier, AccountDraft $draft, ?string $tokenId = null): void
    {
        if (!$this->isCsrfTokenValid($tokenId ?? 'dossier-account-new-'.$dossier->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not yours to change.');
        }
        $draft->number = PostValue::trimmed($request, 'number');
        $draft->label = PostValue::trimmed($request, 'label');
        $draft->lettrable = $request->request->getBoolean('lettrable');
        $draft->collective = $request->request->getBoolean('collective');
    }

    /**
     * @param list<\App\Entity\Accounting\Journal> $counterpartOf
     *
     * @return array<string, mixed>
     */
    private function view(Dossier $dossier, ReferenceCharts $charts, AccountDraft $draft, ?Account $account, ?string $deletionRefusal, array $counterpartOf = []): array
    {
        $chart = null === $dossier->getChartCode() ? null : $charts->get($dossier->getChartCode());
        $number = null === AccountNumber::fault($draft->number) ? $draft->number : ($account?->getNumber() ?? '');

        return [
            'dossier' => $dossier,
            'draft' => $draft,
            'account' => $account,
            'chart' => $chart,
            'root' => '' === $number ? null : $chart?->rootOf($number),
            'shown_number' => $number,
            'nature' => '' === $number ? null : AccountNature::of($number),
            'class_label' => '' === $number ? null : $chart?->classLabel((int) $number[0]),
            'deletion_refusal' => $deletionRefusal,
            'counterpart_of' => $counterpartOf,
        ];
    }
}
