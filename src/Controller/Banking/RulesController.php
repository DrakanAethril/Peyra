<?php

declare(strict_types=1);

namespace App\Controller\Banking;

use App\Entity\Banking\BankRule;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Repository\Banking\BankRuleRepository;
use App\Security\Voter\DossierRightVoter;
use App\Service\Banking\BankingRefused;
use App\Service\Banking\Rule\BankRules;
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
 * Banque › Règles d'affectation (step 2.6a; design-ux.md § 3.2, gabarits L puis F): « si le
 * libellé contient… alors ce compte, ce taux de TVA », tried in order by the reconciliation's
 * panel. « Créer une règle » from a statement line comes here with its wording (`pattern`) and the
 * way back (`return`). Read by whoever works in the dossier, written under DOSSIER_SETTINGS.
 */
#[Route('/d/{dossier}/bank/rules', requirements: ['dossier' => '\d+'])]
final class RulesController extends AbstractController
{
    use BankingControllerTrait;

    #[Route('', name: 'app_bank_rules', methods: ['GET'])]
    public function index(Dossier $dossier, BankRuleRepository $rules): Response
    {
        return $this->render('banking/rules.html.twig', ['dossier' => $dossier, 'bank_rules' => $rules->findInOrder($dossier)]);
    }

    #[Route('/new', name: 'app_bank_rule_new', methods: ['GET', 'POST'])]
    #[Route('/{id}', name: 'app_bank_rule', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Dossier $dossier, Request $request, BankRuleRepository $rules, BankRules $bankRules, #[CurrentUser] User $me, ?int $id = null): Response
    {
        $rule = null;
        if (null !== $id) {
            $rule = $rules->find($id);
            if (null === $rule || $rule->getDossier() !== $dossier) {
                throw new NotFoundHttpException('No such rule.');
            }
        } elseif (!$this->isGranted(DossierRightVoter::SETTINGS, $dossier)) {
            throw new NotFoundHttpException('Not yours to change.');
        }
        $return = ReturnPath::of($request->isMethod('POST') ? PostValue::trimmed($request, 'return') : QueryValue::trimmed($request, 'return'), (int) $dossier->getId());
        $form = [
            'pattern' => $rule?->getPattern() ?? QueryValue::trimmed($request, 'pattern'),
            'account' => $rule?->getAccount() ?? QueryValue::trimmed($request, 'account'),
            'vatRate' => null === $rule?->getVatRate() ? '' : str_replace('.', ',', (string) $rule->getVatRate()),
            'label' => $rule?->getEntryLabel() ?? '',
        ];
        $errors = [];
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            $this->refuseBadToken($dossier, $request);
            if (!$this->isGranted(DossierRightVoter::SETTINGS, $dossier)) {
                throw new NotFoundHttpException('Not yours to change.');
            }
            $form = ['pattern' => PostValue::trimmed($request, 'pattern'), 'account' => PostValue::trimmed($request, 'account'), 'vatRate' => PostValue::trimmed($request, 'vat_rate'), 'label' => PostValue::trimmed($request, 'label')];
            try {
                $saved = $bankRules->save($dossier, $rule, $form['pattern'], $form['account'], $form['vatRate'], $form['label'], $me);
                $this->addFlash('status', \sprintf('Règle « %s » enregistrée.', $saved->getPattern()));

                return null !== $return ? $this->redirect($return, Response::HTTP_SEE_OTHER) : $this->redirectToRoute('app_bank_rules', ['dossier' => $dossier->getId()], Response::HTTP_SEE_OTHER);
            } catch (BankingRefused $refused) {
                $errors = $refused->errors;
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            }
        }

        return $this->render('banking/rule.html.twig', [
            'dossier' => $dossier,
            'rule' => $rule,
            'form' => $form,
            'errors' => $errors,
            'return_path' => $return,
            'can_edit' => $this->isGranted(DossierRightVoter::SETTINGS, $dossier),
        ], new Response(status: $status));
    }

    #[Route('/{id}/delete', name: 'app_bank_rule_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Dossier $dossier, int $id, Request $request, BankRuleRepository $rules, BankRules $bankRules, #[CurrentUser] User $me): Response
    {
        $rule = $rules->find($id);
        $this->refuseBadToken($dossier, $request);
        if (!$rule instanceof BankRule || $rule->getDossier() !== $dossier || !$this->isGranted(DossierRightVoter::SETTINGS, $dossier)) {
            throw new NotFoundHttpException('No such rule.');
        }
        $bankRules->delete($rule, $me);
        $this->addFlash('status', 'Règle supprimée.');

        return $this->redirectToRoute('app_bank_rules', ['dossier' => $dossier->getId()], Response::HTTP_SEE_OTHER);
    }
}
