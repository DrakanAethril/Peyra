<?php

declare(strict_types=1);

namespace App\Controller\Purchasing;

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
 * Achats et stocks › Fournisseurs (step 2.4, gabarit L; design-ux.md § 3.2 « Comme Clients »): the
 * dossier's suppliers - code, name, terms and what is owed to them at the dossier's date,
 * brouillard included. A supplier opens the third party's fiche, where its terms are kept.
 */
#[Route('/d/{dossier}/purchases/suppliers', requirements: ['dossier' => '\d+'])]
final class SuppliersController extends AbstractController
{
    #[Route('', name: 'app_purchase_suppliers', methods: ['GET'])]
    public function index(Dossier $dossier, Request $request, ThirdPartyRepository $thirdParties, LedgerReader $ledger, DueSchedule $schedule): Response
    {
        if (!$this->isGranted(DossierRightVoter::PURCHASES_READ, $dossier)) {
            throw new NotFoundHttpException('The purchases are closed to this profile.');
        }
        $all = array_values(array_filter($thirdParties->findByDossier($dossier), static fn (ThirdParty $t): bool => ThirdPartyKind::Supplier === $t->getKind()));
        $search = QueryValue::trimmed($request, 'q');
        $needle = mb_strtolower($search);
        $found = '' === $search ? $all : array_values(array_filter($all, static fn (ThirdParty $t): bool => str_contains(mb_strtolower($t->getCode().' '.($t->getAuxiliaryCode() ?? '').' '.$t->getName()), $needle)));
        $page = ListPage::fromRequest($request, 'purchases.suppliers')->within(\count($found));

        return $this->render('purchasing/suppliers.html.twig', [
            'dossier' => $dossier,
            'total' => \count($all),
            'supplier_rows' => \array_slice($found, $page->offset(), $page->perPage),
            'shown_total' => \count($found),
            'list_page' => $page,
            'search' => $search,
            'outstanding' => $ledger->thirdPartyTotals($dossier, $ledger->exerciseStart($dossier, $dossier->getDossierDate()), $dossier->getDossierDate(), EntryScope::WithDrafts),
            'overdue' => $schedule->read($dossier, ThirdPartyKind::Supplier, $dossier->getDossierDate())->overdueByThirdParty(),
        ]);
    }
}
