<?php

declare(strict_types=1);

namespace App\Controller\Assets;

use App\Entity\Assets\FixedAsset;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Accounting\AccountingBehaviour;
use App\Enum\Assets\AssetOrigin;
use App\Enum\Assets\DepreciationMethod;
use App\Repository\Accounting\AccountRepository;
use App\Repository\Assets\FixedAssetRepository;
use App\Repository\Dossier\ExerciseRepository;
use App\Security\Voter\DossierRightVoter;
use App\Service\Accounting\Entry\AssistanceLevels;
use App\Service\Assets\FixedAssetDraft;
use App\Service\Assets\FixedAssetPlans;
use App\Service\Assets\FixedAssetPostings;
use App\Service\Assets\FixedAssetRules;
use App\Service\Assets\FixedAssetSheet;
use App\Service\Shared\Assistance\Assistance;
use App\Service\Shared\DateValue;
use App\Service\Shared\Money;
use App\Service\Shared\PostValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Immobilisations et clôture › Immobilisations (lot 3, step 3.4; design-ux.md § 3.2, gabarits L
 * then F): the dossier's fixed assets with what they are depreciated by at the dossier's date, and
 * the fiche of one - account, origin, cost, putting into service, method, length, components - with
 * its depreciation plan as a card. Read with ASSETS_READ, changed with ASSETS_ENTER (the general accounting's area). The plan is
 * calculated at levels G, B and A, and always for a teacher (Assistance::calculatesDepreciationPlan);
 * at 0 the student draws it up alone. The address of a fixed asset is its code. The fiche also carries
 * its acquisition's entry (step 3.4b, FixedAssetPostings: proposed, passed to the brouillard, or to enter)
 * and the impairment at the end of each exercise of the dossier; it is no longer deleted once one of its
 * pièces is in the books.
 */
#[Route('/d/{dossier}/assets', requirements: ['dossier' => '\d+'])]
final class FixedAssetsController extends AbstractController
{
    #[Route('', name: 'app_assets', methods: ['GET'])]
    public function index(Dossier $dossier, FixedAssetRepository $assets, FixedAssetPlans $plans, ExerciseRepository $exercises, AssistanceLevels $levels): Response
    {
        if (!$this->isGranted(DossierRightVoter::ASSETS_READ, $dossier)) {
            throw new NotFoundHttpException('Not for this profile.');
        }
        $exercise = $exercises->findAt($dossier, $dossier->getDossierDate());
        $calculates = $this->calculates($dossier, $levels);
        $rows = [];
        $totals = ['cost' => Money::zero(), 'opening' => Money::zero(), 'dotation' => Money::zero(), 'net' => Money::zero()];
        foreach ($assets->findByDossier($dossier) as $asset) {
            $opening = null;
            $dotation = null;
            $net = $asset->getCost();
            if ($calculates && null !== $exercise) {
                $plan = $plans->plan($dossier, $asset);
                $row = $plan->rowAt($exercise->getEndsOn());
                $opening = $row->opening ?? $plan->cumulativeAt($exercise->getStartsOn()->modify('-1 day'));
                $dotation = $row->dotation ?? Money::zero();
                $net = $asset->getCost()->minus($opening)->minus($dotation);
                $totals['opening'] = $totals['opening']->plus($opening);
                $totals['dotation'] = $totals['dotation']->plus($dotation);
            }
            $totals['cost'] = $totals['cost']->plus($asset->getCost());
            $totals['net'] = $totals['net']->plus($net);
            $rows[] = ['asset' => $asset, 'opening' => $opening, 'dotation' => $dotation, 'net' => $net];
        }

        return $this->render('assets/fixed_assets.html.twig', [
            'dossier' => $dossier,
            'asset_rows' => $rows,
            'totals' => $totals,
            'exercise' => $exercise,
            'calculates' => $calculates && null !== $exercise,
        ]);
    }

    #[Route('/new', name: 'app_assets_new', methods: ['GET', 'POST'])]
    public function create(Dossier $dossier, Request $request, FixedAssetSheet $sheet, FixedAssetPlans $plans, AccountRepository $accounts, AssistanceLevels $levels, FixedAssetPostings $postings, ExerciseRepository $exercises, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        if (!$this->isGranted(DossierRightVoter::ASSETS_ENTER, $dossier)) {
            throw new NotFoundHttpException('Not yours to change.');
        }
        $draft = new FixedAssetDraft();
        $draft->acquiredOn = $dossier->getDossierDate()->format('Y-m-d');
        $draft->inServiceOn = $draft->acquiredOn;
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            $this->read($request, $draft, 'fixed-asset-new-'.$dossier->getId());
            $values = $sheet->check($draft, $dossier);
            if (null !== $values) {
                $sheet->create($dossier, $values, $me);
                $entityManager->flush();
                $this->addFlash('status', \sprintf('Immobilisation %s créée.', $values->code));

                return $this->redirectToRoute('app_assets_fiche', ['dossier' => $dossier->getId(), 'code' => $values->code], Response::HTTP_SEE_OTHER);
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return $this->fiche($dossier, $draft, null, $plans, $accounts, $levels, $postings, $exercises, $sheet, $status);
    }

    #[Route('/{code}', name: 'app_assets_fiche', requirements: ['code' => '[A-Z0-9]{1,12}'], methods: ['GET', 'POST'])]
    public function edit(Dossier $dossier, string $code, Request $request, FixedAssetRepository $assets, FixedAssetSheet $sheet, FixedAssetPlans $plans, AccountRepository $accounts, AssistanceLevels $levels, FixedAssetPostings $postings, ExerciseRepository $exercises, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        if (!$this->isGranted(DossierRightVoter::ASSETS_READ, $dossier)) {
            throw new NotFoundHttpException('Not for this profile.');
        }
        $asset = $assets->findOneByCode($dossier, $code) ?? throw new NotFoundHttpException('No such fixed asset.');
        $draft = FixedAssetDraft::of($asset);
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            if (!$this->isGranted(DossierRightVoter::ASSETS_ENTER, $dossier)) {
                throw new NotFoundHttpException('Not yours to change.');
            }
            $this->read($request, $draft, 'fixed-asset-'.$asset->getId());
            $values = $sheet->check($draft, $dossier, $asset);
            if (null !== $values) {
                $changed = $sheet->update($asset, $values, $me);
                $entityManager->flush();
                $this->addFlash('status', $changed ? \sprintf('Immobilisation %s enregistrée.', $values->code) : 'Rien à enregistrer : la fiche est inchangée.');

                return $this->redirectToRoute('app_assets_fiche', ['dossier' => $dossier->getId(), 'code' => $values->code], Response::HTTP_SEE_OTHER);
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return $this->fiche($dossier, $draft, $asset, $plans, $accounts, $levels, $postings, $exercises, $sheet, $status);
    }

    /** « Passer l'écriture au brouillard »: the acquisition's entry, through the gateway. */
    #[Route('/{code}/acquisition', name: 'app_assets_acquisition_post', requirements: ['code' => '[A-Z0-9]{1,12}'], methods: ['POST'])]
    public function postAcquisition(Dossier $dossier, string $code, Request $request, FixedAssetRepository $assets, FixedAssetPostings $postings, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $asset = $assets->findOneByCode($dossier, $code) ?? throw new NotFoundHttpException('No such fixed asset.');
        if (!$this->isGranted(DossierRightVoter::ASSETS_ENTER, $dossier) || !$this->isCsrfTokenValid('fixed-asset-acquisition-'.$asset->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not yours to change.');
        }
        $proposal = $postings->acquisitionProposal($dossier, $asset);
        if (null === $proposal || AccountingBehaviour::LetEnter === $postings->behaviour($dossier) || [] !== $postings->standing($dossier, $proposal->document)) {
            throw new NotFoundHttpException('This acquisition is not posted from here.');
        }
        $check = $postings->postAcquisition($dossier, $asset, $me);
        if ($check->posted) {
            $entityManager->flush();
            $this->addFlash('status', \sprintf('Écriture d’acquisition %s passée au brouillard.', $proposal->document));
        } else {
            $this->addFlash('error', 'L’écriture d’acquisition n’est pas passée : '.implode(' ', array_unique([...array_values($check->errors), ...array_map(static fn ($control): string => $control->message, array_filter($check->controls(), static fn ($control): bool => $control->blocking))])));
        }

        return $this->redirectToRoute('app_assets_fiche', ['dossier' => $dossier->getId(), 'code' => $asset->getCode()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{code}/delete', name: 'app_assets_delete', requirements: ['code' => '[A-Z0-9]{1,12}'], methods: ['POST'])]
    public function delete(Dossier $dossier, string $code, Request $request, FixedAssetRepository $assets, FixedAssetSheet $sheet, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $asset = $assets->findOneByCode($dossier, $code) ?? throw new NotFoundHttpException('No such fixed asset.');
        if (!$this->isGranted(DossierRightVoter::ASSETS_ENTER, $dossier) || !$this->isCsrfTokenValid('fixed-asset-delete-'.$asset->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not yours to change.');
        }
        if (!$sheet->isDeletable($asset)) {
            $this->addFlash('error', \sprintf('L’immobilisation %s porte des écritures : elle ne se supprime plus.', $code));

            return $this->redirectToRoute('app_assets_fiche', ['dossier' => $dossier->getId(), 'code' => $code], Response::HTTP_SEE_OTHER);
        }
        $sheet->delete($asset, $me);
        $entityManager->flush();
        $this->addFlash('status', \sprintf('Immobilisation %s supprimée.', $code));

        return $this->redirectToRoute('app_assets', ['dossier' => $dossier->getId()], Response::HTTP_SEE_OTHER);
    }

    private function fiche(Dossier $dossier, FixedAssetDraft $draft, ?FixedAsset $asset, FixedAssetPlans $plans, AccountRepository $accounts, AssistanceLevels $levels, FixedAssetPostings $postings, ExerciseRepository $exercises, FixedAssetSheet $sheet, int $status): Response
    {
        $calculates = $this->calculates($dossier, $levels);
        $inService = DateValue::parse($draft->inServiceOn);
        // The components typed, then empty rows: two at least, one more to add, six at most.
        $slots = $draft->components;
        while (\count($slots) < min(FixedAssetRules::MAX_COMPONENTS, max(2, \count($draft->components) + 1))) {
            $slots[] = ['label' => '', 'cost' => null, 'years' => ''];
        }
        // The impairment is given at the end of the dossier's own exercises, up to the one of its date.
        $impairmentEnds = [];
        foreach (array_reverse($exercises->findByDossier($dossier)) as $exercise) {
            if ($exercise->getStartsOn() <= $dossier->getDossierDate() && (null === $inService || $exercise->getEndsOn() >= $inService)) {
                $impairmentEnds[] = $exercise->getEndsOn();
            }
        }
        $acquisition = null;
        if (null !== $asset) {
            $proposal = $postings->acquisitionProposal($dossier, $asset);
            $document = $postings->acquisitionDocument($asset);
            $acquisition = [
                'document' => $document,
                'entries' => $postings->entries($dossier, $document),
                'standing' => $postings->standing($dossier, $document),
                'proposal' => $proposal,
                'check' => null === $proposal ? null : $postings->check($dossier, $proposal),
            ];
        }

        return $this->render('assets/fixed_asset.html.twig', [
            'dossier' => $dossier,
            'draft' => $draft,
            'asset' => $asset,
            'plan' => null !== $asset && $calculates ? $plans->plan($dossier, $asset) : null,
            'calculates' => $calculates,
            'unit_windows' => DepreciationMethod::Units->value === $draft->method && null !== $inService ? $plans->unitWindows($dossier, $inService) : [],
            'impairment_ends' => $impairmentEnds,
            'component_slots' => $slots,
            'origins' => AssetOrigin::cases(),
            'methods' => DepreciationMethod::cases(),
            'account_label' => '' === $draft->account ? null : $accounts->findOneByNumber($dossier, $draft->account)?->getLabel(),
            'depreciation_label' => '' === $draft->depreciationAccount ? null : $accounts->findOneByNumber($dossier, $draft->depreciationAccount)?->getLabel(),
            'acquisition' => $acquisition,
            'behaviour' => $postings->behaviour($dossier),
            'deletable' => null !== $asset && $sheet->isDeletable($asset),
        ], new Response(status: $status));
    }

    private function calculates(Dossier $dossier, AssistanceLevels $levels): bool
    {
        return Assistance::at($levels->for($dossier))->calculatesDepreciationPlan($this->isGranted('ROLE_TEACHER'));
    }

    private function read(Request $request, FixedAssetDraft $draft, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not yours to change.');
        }
        foreach (['code', 'label', 'origin', 'account', 'depreciationAccount', 'acquiredOn', 'inServiceOn', 'supplier', 'document', 'method', 'years', 'totalUnits'] as $field) {
            $draft->{$field} = PostValue::trimmed($request, $field);
        }
        $draft->price = PostValue::cents($request, 'price');
        $draft->incidentalCosts = PostValue::cents($request, 'incidentalCosts');
        $draft->vat = PostValue::cents($request, 'vat');
        $draft->residualValue = PostValue::cents($request, 'residualValue');
        $draft->fiscalDeclining = '1' === PostValue::trimmed($request, 'fiscalDeclining');
        $draft->components = [];
        foreach (PostValue::rows($request, 'components') as $row) {
            $cost = $row['cost'] ?? '';
            $draft->components[] = ['label' => $row['label'] ?? '', 'cost' => 1 === preg_match('/^-?\d+$/', $cost) ? (int) $cost : null, 'years' => $row['years'] ?? ''];
        }
        $draft->unitsUsed = PostValue::stringsByKey($request, 'units');
        $draft->impairments = [];
        foreach (PostValue::stringsByKey($request, 'impairments') as $day => $cents) {
            $draft->impairments[$day] = 1 === preg_match('/^-?\d+$/', $cents) ? (int) $cents : null;
        }
    }
}
