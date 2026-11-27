<?php

declare(strict_types=1);

namespace App\Controller\Accounting;

use App\Entity\Accounting\Account;
use App\Entity\Dossier\Dossier;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Accounting\AccountNature;
use App\Repository\Accounting\AccountRepository;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Service\Accounting\Chart\AccountSearch;
use App\Service\Accounting\Chart\ReferenceCharts;
use App\Service\Shared\QueryValue;
use App\Service\ThirdParty\ThirdPartySearch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * What the account picker (F4, Px:AccountPicker) lists while a person types (design-ui.md § 4.6):
 * the dossier's accounts whose number starts with the digits typed, or whose label holds the words,
 * grouped under their two-digit heading of the reference chart (« 60 · Achats »); then, when the
 * text holds a letter, the third parties kept on a collective account whose auxiliary code, code or
 * name match, under « Tiers » - their auxiliary code (« 401SCIE ») is what the field takes, the
 * collective follows from it (design-ux.md § 5.2). `nature` narrows it to accounts of those natures,
 * third parties left out (a bank journal's counterpart lists the banks). Never leaves the dossier.
 */
final class AccountSearchController extends AbstractController
{
    public const int LIMIT = 50;

    #[Route('/d/{dossier}/accounting/accounts/search', name: 'app_accounting_account_search', requirements: ['dossier' => '\d+'], methods: ['GET'])]
    public function search(Dossier $dossier, Request $request, AccountRepository $accounts, ThirdPartyRepository $thirdParties, ReferenceCharts $charts): JsonResponse
    {
        $query = QueryValue::trimmed($request, 'q');
        $natures = array_values(array_filter(array_map(AccountNature::tryFrom(...), explode(',', QueryValue::trimmed($request, 'nature')))));
        $plan = $accounts->findPlan($dossier);
        if ([] !== $natures) {
            $plan = array_values(array_filter($plan, static fn (Account $a): bool => \in_array($a->getNature(), $natures, true)));
        }
        $found = AccountSearch::filter($plan, static fn (Account $a): array => ['number' => $a->getNumber(), 'label' => $a->getLabel()], $query, self::LIMIT + 1);
        $chart = null === $dossier->getChartCode() ? null : $charts->get($dossier->getChartCode());

        $groups = [];
        foreach (\array_slice($found, 0, self::LIMIT) as $account) {
            $prefix = substr($account->getNumber(), 0, 2);
            $heading = $chart?->groupOf($account->getNumber());
            $groups[$prefix] ??= ['label' => null === $heading ? 'Classe '.$prefix[0] : $prefix.' · '.$heading->label, 'options' => []];
            $groups[$prefix]['options'][] = ['value' => $account->getNumber(), 'label' => $account->getLabel()];
        }

        $more = \count($found) > self::LIMIT;
        $room = self::LIMIT - min(self::LIMIT, \count($found));
        if ([] === $natures && 1 === preg_match('/\pL/u', $query)) {
            $withCode = array_values(array_filter($thirdParties->findByDossier($dossier), static fn (ThirdParty $t): bool => null !== $t->getAuxiliaryCode()));
            $matches = ThirdPartySearch::filter($withCode, static fn (ThirdParty $t): array => ['code' => $t->getCode(), 'auxiliary' => $t->getAuxiliaryCode(), 'name' => $t->getName()], $query, $room + 1);
            $more = $more || \count($matches) > $room;
            $options = [];
            foreach (\array_slice($matches, 0, $room) as $thirdParty) {
                $options[] = ['value' => (string) $thirdParty->getAuxiliaryCode(), 'label' => $thirdParty->getName().' · '.$thirdParty->getAccount()?->getNumber()];
            }
            if ([] !== $options) {
                $groups['tiers'] = ['label' => 'Tiers', 'options' => $options];
            }
        }

        return new JsonResponse([
            'groups' => array_values($groups),
            'more' => $more,
            'empty' => 1 === preg_match('/^\d+$/', $query) ? \sprintf('Aucun compte ne commence par %s.', $query) : \sprintf('Aucun compte ni tiers ne correspond à « %s ».', $query),
        ]);
    }
}
