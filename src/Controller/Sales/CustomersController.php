<?php

declare(strict_types=1);

namespace App\Controller\Sales;

use App\Entity\Dossier\Dossier;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Security\Voter\DossierRightVoter;
use App\Service\Accounting\Ledger\EntryScope;
use App\Service\Accounting\Ledger\LedgerReader;
use App\Service\Shared\ListPage;
use App\Service\Shared\QueryValue;
use App\Service\ThirdParty\Schedule\DueSchedule;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Ventes › Clients (step 2.3, gabarit L): the dossier's customers - code, name, terms and their
 * outstanding amount at the dossier's date, brouillard included. A customer opens the third
 * party's fiche (Comptabilité › Tiers), where its terms are kept: one entity, two doors in.
 */
#[Route('/d/{dossier}/sales/customers', requirements: ['dossier' => '\d+'])]
final class CustomersController extends AbstractController
{
    #[Route('', name: 'app_sales_customers', methods: ['GET'])]
    public function index(Dossier $dossier, Request $request, ThirdPartyRepository $thirdParties, LedgerReader $ledger, DueSchedule $schedule): Response
    {
        if (!$this->isGranted(DossierRightVoter::SALES_READ, $dossier)) {
            throw new NotFoundHttpException('The sales are closed to this profile.');
        }
        $all = array_values(array_filter($thirdParties->findByDossier($dossier), static fn (ThirdParty $t): bool => ThirdPartyKind::Customer === $t->getKind()));
        $search = QueryValue::trimmed($request, 'q');
        $needle = mb_strtolower($search);
        $found = '' === $search ? $all : array_values(array_filter($all, static fn (ThirdParty $t): bool => str_contains(mb_strtolower($t->getCode().' '.($t->getAuxiliaryCode() ?? '').' '.$t->getName()), $needle)));
        $page = ListPage::fromRequest($request, 'sales.customers')->within(\count($found));

        return $this->render('sales/customers.html.twig', [
            'dossier' => $dossier,
            'total' => \count($all),
            'customer_rows' => \array_slice($found, $page->offset(), $page->perPage),
            'shown_total' => \count($found),
            'list_page' => $page,
            'search' => $search,
            'outstanding' => $ledger->thirdPartyTotals($dossier, $ledger->exerciseStart($dossier, $dossier->getDossierDate()), $dossier->getDossierDate(), EntryScope::WithDrafts),
            'overdue' => $schedule->read($dossier, ThirdPartyKind::Customer, $dossier->getDossierDate())->overdueByThirdParty(),
        ]);
    }
}
