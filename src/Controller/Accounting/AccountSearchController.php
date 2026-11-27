<?php

declare(strict_types=1);

namespace App\Controller\Accounting;

use App\Entity\Accounting\Account;
use App\Entity\Dossier\Dossier;
use App\Enum\Accounting\AccountNature;
use App\Repository\Accounting\AccountRepository;
use App\Service\Accounting\Chart\AccountSearch;
use App\Service\Accounting\Chart\ReferenceCharts;
use App\Service\Shared\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * What the account picker (F4, Px:AccountPicker) lists while a person types (design-ui.md § 4.6):
 * the dossier's accounts whose number starts with the digits typed, or whose label holds the words,
 * grouped under their two-digit heading of the reference chart (« 60 · Achats »). `nature` narrows
 * it (a bank journal's counterpart lists the banks). Never leaves the dossier.
 */
final class AccountSearchController extends AbstractController
{
    public const int LIMIT = 50;

    #[Route('/d/{dossier}/accounting/accounts/search', name: 'app_accounting_account_search', requirements: ['dossier' => '\d+'], methods: ['GET'])]
    public function search(Dossier $dossier, Request $request, AccountRepository $accounts, ReferenceCharts $charts): JsonResponse
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

        return new JsonResponse([
            'groups' => array_values($groups),
            'more' => \count($found) > self::LIMIT,
            'empty' => 1 === preg_match('/^\d+$/', $query) ? \sprintf('Aucun compte ne commence par %s.', $query) : \sprintf('Aucun compte ne correspond à « %s ».', $query),
        ]);
    }
}
