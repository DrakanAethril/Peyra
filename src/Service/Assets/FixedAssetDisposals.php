<?php

declare(strict_types=1);

namespace App\Service\Assets;

use App\Entity\Assets\FixedAsset;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Assets\AssetPostingKind;
use App\Enum\Audit\AuditAction;
use App\Repository\Dossier\ExerciseRepository;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Service\Assets\Disposal\DisposalDraft;
use App\Service\Assets\Disposal\DisposalRules;
use App\Service\Assets\Disposal\DisposalValues;
use App\Service\Audit\AuditTrail;
use App\Service\Regulatory\MissingRegulatoryValue;
use App\Service\Regulatory\RegulatoryReader;
use Brick\Math\BigDecimal;

/**
 * The one door to a fixed asset's way out (Immobilisations › fiche › « Céder ou mettre au rebut », step
 * 3.4b): checks it as typed (DisposalRules: the last day the dotations were passed for the asset, the
 * normal VAT rate of the day), records it, or takes it back while no entry of it stands - each gesture
 * in the audit trail. Under « Comptabiliser », recording it writes its entry when the dossier's date has
 * reached its day (FixedAssetPostings::postAtOnce). Runs inside the dossier's context; the caller asks the
 * voter and flushes.
 */
final readonly class FixedAssetDisposals
{
    public function __construct(
        private ThirdPartyRepository $thirdParties,
        private ExerciseRepository $exercises,
        private RegulatoryReader $regulatory,
        private FixedAssetPostings $postings,
        private AuditTrail $auditTrail,
    ) {
    }

    public function check(DisposalDraft $draft, Dossier $dossier, FixedAsset $asset): ?DisposalValues
    {
        $on = $draft->date();
        $exercise = null === $on ? null : $this->exercises->findAt($dossier, $on);
        $rate = null;
        if (null !== $on) {
            try {
                $rate = $this->regulatory->at($on)->rate('fiscal.vat.rates', 'normal');
            } catch (MissingRegulatoryValue) {
                $rate = null;
            }
        }
        if (null !== $on && null === $exercise) {
            $draft->errors = ['disposedOn' => 'Aucun exercice du dossier ne contient cette date.'];

            return null;
        }

        return DisposalRules::check(
            $draft,
            $asset->getAcquiredOn(),
            null === $exercise ? null : $this->postings->lastPassed($dossier, $asset, $exercise, false),
            $asset->getVat(),
            $rate instanceof BigDecimal ? $rate : null,
            fn (string $code) => $this->thirdParties->findOneByCode($dossier, $code) ?? $this->thirdParties->findOneByAuxiliaryCode($dossier, $code),
        );
    }

    /** Whether its entry stands in the books: the way out is no longer taken back. */
    public function isPosted(Dossier $dossier, FixedAsset $asset): bool
    {
        return [] !== $this->postings->standing($dossier, FixedAssetPostings::disposalDocument($asset));
    }

    public function record(Dossier $dossier, FixedAsset $asset, DisposalValues $values, User|string $by): void
    {
        if ($this->isPosted($dossier, $asset)) {
            throw new \LogicException('This way out is in the books already.');
        }
        $asset->dispose($values->kind, $values->on, $values->price, $values->vat, $values->buyer);
        $this->auditTrail->inDossier(
            $dossier,
            AuditAction::FixedAssetDisposed,
            $by,
            \sprintf('Immobilisation %s : %s au %s', $asset->getCode(), mb_strtolower($values->kind->label()), $values->on->format('d/m/Y')),
            ['dossier' => $dossier->getId(), 'code' => $asset->getCode(), 'kind' => $values->kind->value, 'on' => $values->on->format('Y-m-d'), 'price' => $values->price->cents, 'vat' => $values->vat->cents, 'buyer' => $values->buyer?->getCode()],
        );
        $this->postings->postAtOnce($dossier, $asset, AssetPostingKind::Disposal, $by);
    }

    public function cancel(Dossier $dossier, FixedAsset $asset, User $by): void
    {
        if (null === $asset->getDisposedOn() || $this->isPosted($dossier, $asset)) {
            throw new \LogicException('This way out is not taken back.');
        }
        $this->auditTrail->inDossier(
            $dossier,
            AuditAction::FixedAssetDisposalCancelled,
            $by,
            \sprintf('Immobilisation %s : sortie annulée', $asset->getCode()),
            ['dossier' => $dossier->getId(), 'code' => $asset->getCode(), 'on' => $asset->getDisposedOn()->format('Y-m-d')],
        );
        $asset->cancelDisposal();
    }
}
