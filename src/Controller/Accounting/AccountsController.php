<?php

declare(strict_types=1);

namespace App\Controller\Accounting;

use App\Entity\Accounting\Account;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Repository\Accounting\AccountRepository;
use App\Repository\Dossier\ExerciseRepository;
use App\Security\Voter\DossierRightVoter;
use App\Service\Accounting\Chart\AccountSearch;
use App\Service\Accounting\Chart\ChartCsv;
use App\Service\Accounting\Chart\ChartInstaller;
use App\Service\Accounting\Chart\ReferenceChart;
use App\Service\Accounting\Chart\ReferenceCharts;
use App\Service\Shared\ListPage;
use App\Service\Shared\QueryValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Comptabilité › Paramètres comptables › Plan de comptes (design-ux.md § 3.2, gabarit L): the
 * dossier's plan, filtered by class and searched by number or label like the account picker (F4),
 * its export as CSV, and - for a plan still empty - the installation of the reference chart of its
 * exercise. An account opens its fiche (AccountController); the import has its screen
 * (AccountImportController).
 */
#[Route('/d/{dossier}/accounting/accounts', requirements: ['dossier' => '\d+'])]
final class AccountsController extends AbstractController
{
    #[Route('', name: 'app_accounting_accounts', methods: ['GET'])]
    public function index(Dossier $dossier, Request $request, AccountRepository $accounts, ReferenceCharts $charts, ExerciseRepository $exercises): Response
    {
        $plan = $accounts->findPlan($dossier);
        $search = QueryValue::trimmed($request, 'q');
        $class = QueryValue::nullableInt($request, 'class');
        $class = null !== $class && $class >= 1 && $class <= 8 ? $class : null;

        $found = AccountSearch::filter($plan, static fn (Account $a): array => ['number' => $a->getNumber(), 'label' => $a->getLabel()], $search);
        $counts = ['' => \count($found)];
        foreach (range(1, 8) as $digit) {
            $counts[$digit] = 0;
        }
        foreach ($found as $account) {
            ++$counts[$account->getClassDigit()];
        }
        $shown = null === $class ? $found : array_values(array_filter($found, static fn (Account $a): bool => $a->getClassDigit() === $class));
        $page = ListPage::fromRequest($request, 'accounting.accounts')->within(\count($shown));
        $chart = null === $dossier->getChartCode() ? null : $charts->get($dossier->getChartCode());

        return $this->render('accounting/accounts.html.twig', [
            'dossier' => $dossier,
            'plan_size' => \count($plan),
            'account_rows' => \array_slice($shown, $page->offset(), $page->perPage),
            'shown_total' => \count($shown),
            'list_page' => $page,
            'counts' => $counts,
            'class' => $class,
            'search' => $search,
            'chart' => $chart,
            'class_labels' => ($chart ?? $charts->all()[0])->classLabels(),
            'installable' => [] === $plan ? $this->installableChart($dossier, $charts, $exercises) : null,
        ]);
    }

    #[Route('/export.csv', name: 'app_accounting_accounts_export', methods: ['GET'])]
    public function export(Dossier $dossier, AccountRepository $accounts): Response
    {
        $response = new Response(ChartCsv::export($accounts->findPlan($dossier)));
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $name = 'plan-de-comptes-'.(new AsciiSlugger('fr'))->slug($dossier->getName())->lower().'.csv';
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name));

        return $response;
    }

    #[Route('/install', name: 'app_accounting_accounts_install', methods: ['POST'])]
    public function install(Dossier $dossier, Request $request, AccountRepository $accounts, ReferenceCharts $charts, ExerciseRepository $exercises, ChartInstaller $installer, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        if (!$this->isGranted(DossierRightVoter::SETTINGS, $dossier) || !$this->isCsrfTokenValid('accounting-install-'.$dossier->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not yours to change.');
        }
        if (0 === $accounts->countPlan($dossier)) {
            $chart = $this->installableChart($dossier, $charts, $exercises);
            $count = $installer->install($dossier, $chart, $me);
            $entityManager->flush();
            $this->addFlash('status', \sprintf('%s installé : %d comptes.', $chart->label, $count));
        }

        return $this->redirectToRoute('app_accounting_accounts', ['dossier' => $dossier->getId()], Response::HTTP_SEE_OTHER);
    }

    /** The chart of the exercise the dossier's date falls in (or of that date). */
    private function installableChart(Dossier $dossier, ReferenceCharts $charts, ExerciseRepository $exercises): ReferenceChart
    {
        $exercise = $exercises->findAt($dossier, $dossier->getDossierDate());

        return $charts->forExerciseStartingOn($exercise?->getStartsOn() ?? $dossier->getDossierDate());
    }
}
