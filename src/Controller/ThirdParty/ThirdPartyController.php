<?php

declare(strict_types=1);

namespace App\Controller\ThirdParty;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\ThirdParty\PaymentMethod;
use App\Enum\ThirdParty\PaymentTermKind;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Repository\Accounting\AccountRepository;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Security\Voter\DossierVoter;
use App\Service\Accounting\Ledger\EntryScope;
use App\Service\Accounting\Ledger\LedgerReader;
use App\Service\Shared\PostValue;
use App\Service\Shared\QueryValue;
use App\Service\ThirdParty\ThirdPartyDraft;
use App\Service\ThirdParty\ThirdPartySheet;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The fiche of a third party (gabarit F): identity, account and auxiliary code, details, payment
 * terms and mode. F10 saves for whoever works in the dossier (DossierVoter::EDIT), anybody else
 * reads; the two-step button deletes. The address is the third party's code; a new one starts on
 * the next code of its nature and its usual account.
 */
#[Route('/d/{dossier}/accounting/third-parties', requirements: ['dossier' => '\d+'])]
final class ThirdPartyController extends AbstractController
{
    private const string CODE = '[A-Z0-9]{1,12}';

    #[Route('/new', name: 'app_third_party_new', methods: ['GET', 'POST'])]
    public function create(Dossier $dossier, Request $request, ThirdPartySheet $sheet, AccountRepository $accounts, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        if (!$this->isGranted(DossierVoter::EDIT, $dossier)) {
            throw new NotFoundHttpException('Not yours to change.');
        }
        $draft = $sheet->blank($dossier, ThirdPartyKind::tryFrom(QueryValue::trimmed($request, 'kind')) ?? ThirdPartyKind::Customer);
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            $this->read($request, $draft, 'third-party-new-'.$dossier->getId());
            $fields = $sheet->check($draft, $dossier);
            if (null !== $fields) {
                $sheet->create($dossier, $draft, $fields, $me);
                $entityManager->flush();
                $this->addFlash('status', \sprintf('Tiers %s créé.', $draft->code).(null !== $draft->notice ? ' '.$draft->notice : ''));

                return $this->redirectToRoute('app_third_party', ['dossier' => $dossier->getId(), 'code' => $draft->code], Response::HTTP_SEE_OTHER);
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return $this->render('third_party/fiche.html.twig', $this->view($dossier, $draft, null, $accounts), new Response(status: $status));
    }

    #[Route('/{code}', name: 'app_third_party', requirements: ['code' => self::CODE], methods: ['GET', 'POST'])]
    public function edit(Dossier $dossier, string $code, Request $request, ThirdPartyRepository $thirdParties, ThirdPartySheet $sheet, AccountRepository $accounts, LedgerReader $ledger, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $thirdParty = $thirdParties->findOneByCode($dossier, $code) ?? throw new NotFoundHttpException('No such third party.');
        $draft = ThirdPartyDraft::of($thirdParty);
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            if (!$this->isGranted(DossierVoter::EDIT, $dossier)) {
                throw new NotFoundHttpException('Not yours to change.');
            }
            $this->read($request, $draft, 'third-party-'.$thirdParty->getId());
            $fields = $sheet->check($draft, $dossier, $thirdParty);
            if (null !== $fields) {
                $changed = $sheet->update($thirdParty, $draft, $fields, $me);
                $entityManager->flush();
                $this->addFlash('status', $changed ? \sprintf('Tiers %s enregistré.', $draft->code).(null !== $draft->notice ? ' '.$draft->notice : '') : 'Rien à enregistrer : le tiers est inchangé.');

                return $this->redirectToRoute('app_third_party', ['dossier' => $dossier->getId(), 'code' => $draft->code], Response::HTTP_SEE_OTHER);
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        $today = $dossier->getDossierDate();
        $view = $this->view($dossier, $draft, $thirdParty, $accounts);
        $view['outstanding'] = $ledger->thirdPartyBalance($thirdParty, $ledger->exerciseStart($dossier, $today), $today, EntryScope::WithDrafts);
        $view['deletion_refusal'] = $sheet->deletionRefusal($thirdParty);

        return $this->render('third_party/fiche.html.twig', $view, new Response(status: $status));
    }

    #[Route('/{code}/delete', name: 'app_third_party_delete', requirements: ['code' => self::CODE], methods: ['POST'])]
    public function delete(Dossier $dossier, string $code, Request $request, ThirdPartyRepository $thirdParties, ThirdPartySheet $sheet, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $thirdParty = $thirdParties->findOneByCode($dossier, $code) ?? throw new NotFoundHttpException('No such third party.');
        if (!$this->isGranted(DossierVoter::EDIT, $dossier) || !$this->isCsrfTokenValid('third-party-delete-'.$thirdParty->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not yours to change.');
        }
        $refusal = $sheet->deletionRefusal($thirdParty);
        if (null !== $refusal) {
            $this->addFlash('status', $refusal);

            return $this->redirectToRoute('app_third_party', ['dossier' => $dossier->getId(), 'code' => $code], Response::HTTP_SEE_OTHER);
        }
        $sheet->delete($thirdParty, $me);
        $entityManager->flush();
        $this->addFlash('status', \sprintf('Tiers %s supprimé.', $code));

        return $this->redirectToRoute('app_third_parties', ['dossier' => $dossier->getId()], Response::HTTP_SEE_OTHER);
    }

    private function read(Request $request, ThirdPartyDraft $draft, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not yours to change.');
        }
        foreach (['code', 'kind', 'name', 'account', 'auxiliaryCode', 'siren', 'vatNumber', 'street', 'street2', 'postalCode', 'city', 'country', 'contactName', 'email', 'phone', 'termKind', 'termDays', 'termDay', 'paymentMethod', 'note'] as $field) {
            $draft->{$field} = PostValue::trimmed($request, $field);
        }
        $draft->individual = $request->request->getBoolean('individual');
    }

    /** @return array<string, mixed> */
    private function view(Dossier $dossier, ThirdPartyDraft $draft, ?ThirdParty $thirdParty, AccountRepository $accounts): array
    {
        $account = '' === $draft->account ? null : $accounts->findOneByNumber($dossier, $draft->account);
        $natures = [];
        foreach (ThirdPartyKind::Other->accountNatures() as $nature) {
            $natures[] = $nature->value;
        }

        return [
            'dossier' => $dossier,
            'draft' => $draft,
            'third_party' => $thirdParty,
            'outstanding' => null,
            'deletion_refusal' => null,
            'account_label' => $account?->getLabel(),
            'account_natures' => implode(',', $natures),
            'kind_options' => array_map(static fn (ThirdPartyKind $k): array => ['value' => $k->value, 'label' => $k->label()], ThirdPartyKind::cases()),
            'term_options' => array_map(static fn (PaymentTermKind $k): array => ['value' => $k->value, 'label' => $k->label()], PaymentTermKind::cases()),
            'method_options' => array_map(static fn (PaymentMethod $m): array => ['value' => $m->value, 'label' => $m->label()], PaymentMethod::cases()),
        ];
    }
}
