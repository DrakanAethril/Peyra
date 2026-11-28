<?php

declare(strict_types=1);

namespace App\Controller\Assets;

use App\Entity\Assets\FixedAsset;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Accounting\AccountingBehaviour;
use App\Enum\Assets\DisposalKind;
use App\Repository\Assets\FixedAssetRepository;
use App\Security\Voter\DossierRightVoter;
use App\Service\Accounting\Entry\AssistanceLevels;
use App\Service\Assets\Disposal\DisposalDraft;
use App\Service\Assets\FixedAssetDisposals;
use App\Service\Assets\FixedAssetPostings;
use App\Service\Shared\Assistance\Assistance;
use App\Service\Shared\PostValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * A fixed asset's way out (step 3.4b, gabarit E; design-ux.md § 3.2 « Cessions »): a sale or a scrapping,
 * its day, price, VAT and buyer (« Enregistrer la cession », F10), the detailed calculation - the
 * dotation up to its day, the depreciation written back, the net book value, the gain or loss, the
 * reversals, the VAT given back - shown at levels G, B and A and to a teacher, and its entry through the
 * gateway (FixedAssetPostings), once the dossier's date reaches its day. Taken back while its entry is
 * not in the books. Read with ASSETS_READ, changed with ASSETS_ENTER.
 */
#[Route('/d/{dossier}/assets/{code}/disposal', requirements: ['dossier' => '\d+', 'code' => '[A-Z0-9]{1,12}'])]
final class FixedAssetDisposalController extends AbstractController
{
    #[Route('', name: 'app_assets_disposal', methods: ['GET', 'POST'])]
    public function edit(Dossier $dossier, string $code, Request $request, FixedAssetRepository $assets, FixedAssetDisposals $disposals, FixedAssetPostings $postings, AssistanceLevels $levels, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        if (!$this->isGranted(DossierRightVoter::ASSETS_READ, $dossier)) {
            throw new NotFoundHttpException('Not for this profile.');
        }
        $asset = $assets->findOneByCode($dossier, $code) ?? throw new NotFoundHttpException('No such fixed asset.');
        $draft = DisposalDraft::of($asset, $dossier->getDossierDate());
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            if (!$this->isGranted(DossierRightVoter::ASSETS_ENTER, $dossier) || !$this->isCsrfTokenValid('fixed-asset-disposal-'.$asset->getId(), $request->request->getString('_token')) || $disposals->isPosted($dossier, $asset)) {
                throw new NotFoundHttpException('Not yours to change.');
            }
            $draft->kind = PostValue::trimmed($request, 'kind');
            $draft->disposedOn = PostValue::trimmed($request, 'disposedOn');
            $draft->price = PostValue::cents($request, 'price');
            $draft->vat = PostValue::cents($request, 'vat');
            $draft->buyer = PostValue::trimmed($request, 'buyer');
            $values = $disposals->check($draft, $dossier, $asset);
            if (null !== $values) {
                $disposals->record($dossier, $asset, $values, $me);
                $entityManager->flush();
                $this->addFlash('status', \sprintf('%s de l’immobilisation %s enregistrée.', $values->kind->label(), $asset->getCode()));

                return $this->redirectToRoute('app_assets_disposal', ['dossier' => $dossier->getId(), 'code' => $asset->getCode()], Response::HTTP_SEE_OTHER);
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return $this->page($dossier, $asset, $draft, $disposals, $postings, $levels, $status);
    }

    #[Route('/post', name: 'app_assets_disposal_post', methods: ['POST'])]
    public function post(Dossier $dossier, string $code, Request $request, FixedAssetRepository $assets, FixedAssetPostings $postings, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $asset = $this->gesture($dossier, $code, $request, $assets, 'fixed-asset-disposal-post-');
        $proposal = $postings->disposalProposal($dossier, $asset);
        if (null === $proposal || AccountingBehaviour::LetEnter === $postings->behaviour($dossier) || [] !== $postings->standing($dossier, $proposal->document)) {
            throw new NotFoundHttpException('This way out is not posted from here.');
        }
        $check = $postings->postDisposal($dossier, $asset, $me);
        if ($check->posted) {
            $entityManager->flush();
            $this->addFlash('status', \sprintf('Écriture %s passée au brouillard.', $proposal->document));
        } else {
            $this->addFlash('error', 'L’écriture de sortie n’est pas passée : '.implode(' ', array_unique([...array_values($check->errors), ...array_map(static fn ($control): string => $control->message, array_filter($check->controls(), static fn ($control): bool => $control->blocking))])));
        }

        return $this->redirectToRoute('app_assets_disposal', ['dossier' => $dossier->getId(), 'code' => $asset->getCode()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/cancel', name: 'app_assets_disposal_cancel', methods: ['POST'])]
    public function cancel(Dossier $dossier, string $code, Request $request, FixedAssetRepository $assets, FixedAssetDisposals $disposals, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $asset = $this->gesture($dossier, $code, $request, $assets, 'fixed-asset-disposal-cancel-');
        if (null === $asset->getDisposedOn() || $disposals->isPosted($dossier, $asset)) {
            throw new NotFoundHttpException('This way out is not taken back.');
        }
        $disposals->cancel($dossier, $asset, $me);
        $entityManager->flush();
        $this->addFlash('status', \sprintf('Sortie de l’immobilisation %s annulée.', $asset->getCode()));

        return $this->redirectToRoute('app_assets_fiche', ['dossier' => $dossier->getId(), 'code' => $asset->getCode()], Response::HTTP_SEE_OTHER);
    }

    private function gesture(Dossier $dossier, string $code, Request $request, FixedAssetRepository $assets, string $token): FixedAsset
    {
        $asset = $assets->findOneByCode($dossier, $code) ?? throw new NotFoundHttpException('No such fixed asset.');
        if (!$this->isGranted(DossierRightVoter::ASSETS_ENTER, $dossier) || !$this->isCsrfTokenValid($token.$asset->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not yours to change.');
        }

        return $asset;
    }

    private function page(Dossier $dossier, FixedAsset $asset, DisposalDraft $draft, FixedAssetDisposals $disposals, FixedAssetPostings $postings, AssistanceLevels $levels, int $status): Response
    {
        $proposal = $postings->disposalProposal($dossier, $asset);
        $document = FixedAssetPostings::disposalDocument($asset);

        return $this->render('assets/disposal.html.twig', [
            'dossier' => $dossier,
            'asset' => $asset,
            'draft' => $draft,
            'kinds' => DisposalKind::cases(),
            'posted' => $disposals->isPosted($dossier, $asset),
            'calculation' => $postings->disposalCalculation($dossier, $asset),
            'calculates' => Assistance::at($levels->for($dossier))->calculatesDepreciationPlan($this->isGranted('ROLE_TEACHER')),
            'behaviour' => $postings->behaviour($dossier),
            'document' => $document,
            'entry' => [
                'entries' => $postings->entries($dossier, $document),
                'standing' => $postings->standing($dossier, $document),
                'proposal' => $proposal,
                'check' => null === $proposal ? null : $postings->check($dossier, $proposal),
            ],
        ], new Response(status: $status));
    }
}
