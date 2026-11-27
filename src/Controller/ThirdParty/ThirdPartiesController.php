<?php

declare(strict_types=1);

namespace App\Controller\ThirdParty;

use App\Entity\Dossier\Dossier;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Repository\Accounting\AccountRepository;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Service\Accounting\Ledger\EntryScope;
use App\Service\Accounting\Ledger\LedgerReader;
use App\Service\Shared\ListPage;
use App\Service\Shared\QueryValue;
use App\Service\ThirdParty\ThirdPartyCsv;
use App\Service\ThirdParty\ThirdPartySearch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Comptabilité › Paramètres comptables › Tiers (gabarit L): the dossier's third parties by code,
 * filtered by nature (and by account, from an account's fiche), searched by code, auxiliary code
 * or name like the account picker (F4); its export as CSV. A third party opens its fiche
 * (ThirdPartyController); the import has its screen (ThirdPartyImportController). « Encours » is
 * each third party's balance at the dossier's date, brouillard included (LedgerReader).
 */
#[Route('/d/{dossier}/accounting/third-parties', requirements: ['dossier' => '\d+'])]
final class ThirdPartiesController extends AbstractController
{
    #[Route('', name: 'app_third_parties', methods: ['GET'])]
    public function index(Dossier $dossier, Request $request, ThirdPartyRepository $thirdParties, AccountRepository $accounts, LedgerReader $ledger): Response
    {
        $all = $thirdParties->findByDossier($dossier);
        $search = QueryValue::trimmed($request, 'q');
        $kind = ThirdPartyKind::tryFrom(QueryValue::trimmed($request, 'kind'));
        $account = '' === QueryValue::trimmed($request, 'account') ? null : $accounts->findOneByNumber($dossier, QueryValue::trimmed($request, 'account'));

        $found = ThirdPartySearch::filter($all, self::read(...), $search);
        if (null !== $account) {
            $found = array_values(array_filter($found, static fn (ThirdParty $t): bool => $t->getAccount() === $account));
        }
        $counts = ['' => \count($found)];
        foreach (ThirdPartyKind::cases() as $case) {
            $counts[$case->value] = 0;
        }
        foreach ($found as $thirdParty) {
            ++$counts[$thirdParty->getKind()->value];
        }
        $shown = null === $kind ? $found : array_values(array_filter($found, static fn (ThirdParty $t): bool => $t->getKind() === $kind));
        $page = ListPage::fromRequest($request, 'third_parties')->within(\count($shown));

        return $this->render('third_party/index.html.twig', [
            'dossier' => $dossier,
            'total' => \count($all),
            'third_party_rows' => \array_slice($shown, $page->offset(), $page->perPage),
            'shown_total' => \count($shown),
            'list_page' => $page,
            'counts' => $counts,
            'kinds' => ThirdPartyKind::cases(),
            'kind' => $kind,
            'search' => $search,
            'account' => $account,
            'outstanding' => $ledger->thirdPartyTotals($dossier, $ledger->exerciseStart($dossier, $dossier->getDossierDate()), $dossier->getDossierDate(), EntryScope::WithDrafts),
        ]);
    }

    #[Route('/export.csv', name: 'app_third_parties_export', methods: ['GET'])]
    public function export(Dossier $dossier, ThirdPartyRepository $thirdParties): Response
    {
        $response = new Response(ThirdPartyCsv::export($thirdParties->findByDossier($dossier)));
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $name = 'tiers-'.(new AsciiSlugger('fr'))->slug($dossier->getName())->lower().'.csv';
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name));

        return $response;
    }

    /** @return array{code: string, auxiliary: ?string, name: string} */
    public static function read(ThirdParty $thirdParty): array
    {
        return ['code' => $thirdParty->getCode(), 'auxiliary' => $thirdParty->getAuxiliaryCode(), 'name' => $thirdParty->getName()];
    }
}
