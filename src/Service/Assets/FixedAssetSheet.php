<?php

declare(strict_types=1);

namespace App\Service\Assets;

use App\Entity\Assets\FixedAsset;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Assets\AssetPostingKind;
use App\Enum\Audit\AuditAction;
use App\Repository\Accounting\AccountRepository;
use App\Repository\Assets\FixedAssetRepository;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Service\Audit\AuditTrail;
use App\Service\Shared\DateValue;
use App\Service\Shared\FrenchFormat;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one door to a dossier's fixed assets (Immobilisations et clôture › Immobilisations): checks a
 * fiche as typed (FixedAssetRules, every fault under its field), creates, changes and deletes it -
 * each gesture in the audit trail. A fiche writes nothing in the books (its entries go through the
 * accounting gateway, FixedAssetPostings: under « Comptabiliser » its acquisition is written at its
 * creation); an impairment stays under the net book value its plan gives; a fiche whose pièces have
 * entries in the books is no longer deleted. Runs inside the dossier's context; the caller asks the
 * voter and flushes.
 */
final readonly class FixedAssetSheet
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private FixedAssetRepository $assets,
        private AccountRepository $accounts,
        private ThirdPartyRepository $thirdParties,
        private AuditTrail $auditTrail,
        private FixedAssetPlans $plans,
        private FixedAssetPostings $postings,
    ) {
    }

    public function check(FixedAssetDraft $draft, Dossier $dossier, ?FixedAsset $asset = null): ?FixedAssetValues
    {
        $values = $this->rules($draft, $dossier, $asset);
        if (null === $values || [] === array_filter($values->impairments)) {
            return $values;
        }
        // The plan of the fiche as typed says what the net book value is at each exercise's end.
        $probe = new FixedAsset($dossier, $values->account, $values->acquiredOn);
        $probe->describe($values);
        $plan = $this->plans->plan($dossier, $probe);
        foreach ($values->impairments as $day => $cents) {
            $end = DateValue::parse((string) $day);
            if (null === $end) {
                continue;
            }
            $net = $values->price->plus($values->incidentalCosts)->minus($plan->cumulativeAt($end));
            if ($cents > $net->cents) {
                $draft->errors['impairments'] = \sprintf('La dépréciation au %s dépasse la valeur nette comptable de ce jour-là, %s.', FrenchFormat::date($end), FrenchFormat::amount($net));

                return null;
            }
        }

        return $values;
    }

    /** Whether the fiche may be deleted: none of its pièces has an entry in the books. */
    public function isDeletable(FixedAsset $asset): bool
    {
        return !$this->postings->carriesEntries($asset);
    }

    private function rules(FixedAssetDraft $draft, Dossier $dossier, ?FixedAsset $asset): ?FixedAssetValues
    {
        return FixedAssetRules::check(
            $draft,
            fn (string $number) => $this->accounts->findOneByNumber($dossier, $number),
            fn (string $code) => $this->thirdParties->findOneByCode($dossier, $code) ?? $this->thirdParties->findOneByAuxiliaryCode($dossier, $code),
            function (string $code) use ($dossier, $asset): ?string {
                $holder = $this->assets->findOneByCode($dossier, $code);

                return null === $holder || $holder === $asset ? null : $holder->getLabel();
            },
        );
    }

    public function create(Dossier $dossier, FixedAssetValues $values, User|string $by): FixedAsset
    {
        $asset = new FixedAsset($dossier, $values->account, $values->acquiredOn);
        $asset->describe($values);
        $this->entityManager->persist($asset);
        $this->auditTrail->inDossier(
            $dossier,
            AuditAction::FixedAssetCreated,
            $by,
            \sprintf('Immobilisation %s %s créée', $values->code, $values->label),
            ['dossier' => $dossier->getId(), 'code' => $values->code, 'account' => $values->account->getNumber(), 'cost' => $asset->getCost()->cents, 'method' => $values->method->value],
        );
        $this->postings->postAtOnce($dossier, $asset, AssetPostingKind::Acquisition, $by);

        return $asset;
    }

    /** False when nothing changed (nothing is written). */
    public function update(FixedAsset $asset, FixedAssetValues $values, User $by): bool
    {
        $before = self::snapshot($asset);
        $asset->describe($values);
        if (self::snapshot($asset) === $before) {
            return false;
        }
        $this->auditTrail->inDossier(
            $asset->getDossier(),
            AuditAction::FixedAssetChanged,
            $by,
            \sprintf('Immobilisation %s modifiée', $values->code),
            ['dossier' => $asset->getDossier()->getId(), 'before' => $before],
        );

        return true;
    }

    public function delete(FixedAsset $asset, User $by): void
    {
        if (!$this->isDeletable($asset)) {
            throw new \LogicException('A fiche with entries is not deleted.');
        }
        $this->auditTrail->inDossier(
            $asset->getDossier(),
            AuditAction::FixedAssetDeleted,
            $by,
            \sprintf('Immobilisation %s %s supprimée', $asset->getCode(), $asset->getLabel()),
            ['dossier' => $asset->getDossier()->getId(), 'fiche' => self::snapshot($asset)],
        );
        $this->entityManager->remove($asset);
    }

    /** @return array<string, mixed> what the trail keeps of a fiche */
    private static function snapshot(FixedAsset $asset): array
    {
        return [
            'code' => $asset->getCode(),
            'label' => $asset->getLabel(),
            'origin' => $asset->getOrigin()->value,
            'account' => $asset->getAccount()->getNumber(),
            'depreciationAccount' => $asset->getDepreciationAccount()?->getNumber(),
            'acquiredOn' => $asset->getAcquiredOn()->format('Y-m-d'),
            'inServiceOn' => $asset->getInServiceOn()->format('Y-m-d'),
            'price' => $asset->getPrice()->cents,
            'incidentalCosts' => $asset->getIncidentalCosts()->cents,
            'vat' => $asset->getVat()->cents,
            'supplier' => $asset->getSupplier()?->getCode(),
            'document' => $asset->getDocument(),
            'residualValue' => $asset->getResidualValue()->cents,
            'method' => $asset->getMethod()->value,
            'years' => $asset->getYears(),
            'totalUnits' => $asset->getTotalUnits(),
            'unitsUsed' => $asset->getUnitsUsed(),
            'fiscalDeclining' => $asset->isFiscalDeclining(),
            'components' => $asset->getComponents(),
            'impairments' => $asset->getImpairments(),
        ];
    }
}
