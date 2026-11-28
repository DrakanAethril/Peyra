<?php

declare(strict_types=1);

namespace App\Service\Assets\Statement;

use App\Entity\Assets\FixedAsset;
use App\Entity\Dossier\Dossier;
use App\Enum\Assets\DepreciationMethod;
use App\Repository\Assets\FixedAssetRepository;
use App\Repository\Dossier\ExerciseRepository;
use App\Service\Assets\Depreciation\DepreciationPlan;
use App\Service\Assets\FixedAssetPlans;
use App\Service\Assets\FixedAssetPostings;
use App\Service\Shared\Money;

/**
 * Reads the state of fixed assets and depreciation (Immobilisations et clôture › État des
 * immobilisations, step 3.4b) from the fiches and their plans - never from the books - for the exercise
 * of the dossier's date, drawn up on that date (or on the exercise's end once it is past): an asset
 * entered after that day is not in it, one gone by then left it. Grouped as the annexe groups them:
 * intangible (20), tangible (21), in progress (23), financial assets (26, 27).
 */
final readonly class FixedAssetStatements
{
    private const array GROUPS = [
        'intangible' => 'Immobilisations incorporelles',
        'tangible' => 'Immobilisations corporelles',
        'in_progress' => 'Immobilisations en cours',
        'financial' => 'Immobilisations financières',
    ];

    public function __construct(
        private FixedAssetRepository $assets,
        private ExerciseRepository $exercises,
        private FixedAssetPlans $plans,
    ) {
    }

    public function at(Dossier $dossier): ?FixedAssetStatement
    {
        $exercise = $this->exercises->findAt($dossier, $dossier->getDossierDate());
        if (null === $exercise) {
            return null;
        }
        $at = min($dossier->getDossierDate(), $exercise->getEndsOn());
        $start = $exercise->getStartsOn();
        $before = $start->modify('-1 day');
        $byGroup = [];
        foreach ($this->assets->findByDossier($dossier) as $asset) {
            $entered = FixedAssetPostings::acquisitionDay($asset);
            if ($entered > $at || $asset->isDisposedBy($before)) {
                continue;
            }
            $byGroup[self::group($asset)][] = $this->row($dossier, $asset, $start, $before, $at);
        }
        $groups = [];
        foreach (self::GROUPS as $key => $label) {
            if (isset($byGroup[$key])) {
                $groups[] = new StatementGroup($key, $label, $byGroup[$key], StatementRow::total('Total '.mb_strtolower($label), $byGroup[$key]));
            }
        }

        return new FixedAssetStatement($exercise, $at, $groups, StatementRow::total('Total des immobilisations', array_map(static fn (StatementGroup $group): StatementRow => $group->total, $groups)));
    }

    private function row(Dossier $dossier, FixedAsset $asset, \DateTimeImmutable $start, \DateTimeImmutable $before, \DateTimeImmutable $at): StatementRow
    {
        $cost = $asset->getCost();
        $isNew = FixedAssetPostings::acquisitionDay($asset) >= $start;
        $gone = $asset->isDisposedBy($at);
        $depreciationOpening = Money::zero();
        $dotations = Money::zero();
        if (DepreciationMethod::None !== $asset->getMethod()) {
            $depreciationOpening = $isNew ? Money::zero() : $this->plans->plan($dossier, $asset, $before)->cumulativeAt($before);
            $dotations = $this->plans->plan($dossier, $asset, $at)->cumulativeAt($at)->minus($depreciationOpening);
        }
        $balances = $asset->impairmentBalances();
        $impairmentOpening = DepreciationPlan::impairmentAt($balances, $before) ?? Money::zero();
        $movement = (DepreciationPlan::impairmentAt($balances, $at) ?? Money::zero())->minus($impairmentOpening);
        if ($gone) {
            $movement = Money::zero();
        }

        return new StatementRow(
            $asset->getCode(),
            $asset->getLabel(),
            $asset->getAccount()->getNumber(),
            $isNew ? Money::zero() : $cost,
            $isNew ? $cost : Money::zero(),
            $gone ? $cost : Money::zero(),
            $depreciationOpening,
            $dotations,
            $gone ? $depreciationOpening->plus($dotations) : Money::zero(),
            $impairmentOpening,
            $movement->isPositive() ? $movement : Money::zero(),
            ($movement->isNegative() ? $movement->abs() : Money::zero())->plus($gone ? $impairmentOpening : Money::zero()),
        );
    }

    private static function group(FixedAsset $asset): string
    {
        $number = $asset->getAccount()->getNumber();

        return match (true) {
            str_starts_with($number, '20') => 'intangible',
            str_starts_with($number, '23') => 'in_progress',
            str_starts_with($number, '26'), str_starts_with($number, '27') => 'financial',
            default => 'tangible',
        };
    }
}
