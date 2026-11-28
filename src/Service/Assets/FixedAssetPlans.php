<?php

declare(strict_types=1);

namespace App\Service\Assets;

use App\Entity\Assets\FixedAsset;
use App\Entity\Dossier\Dossier;
use App\Enum\Assets\DepreciationMethod;
use App\Repository\Dossier\ExerciseRepository;
use App\Service\Assets\Depreciation\DecliningCoefficients;
use App\Service\Assets\Depreciation\DepreciationPlan;
use App\Service\Assets\Depreciation\ExerciseWindows;
use App\Service\Assets\Depreciation\PlanInput;
use App\Service\Regulatory\MissingRegulatoryValue;
use App\Service\Regulatory\RegulatoryReader;
use App\Service\Shared\Money;

/**
 * A fixed asset's depreciation plan in its dossier (step 3.4): DepreciationPlan laid on the
 * dossier's exercises (ExerciseWindows), the declining coefficient read in the regulatory values
 * on the day of acquisition, each component's plan added up. Read at each display, never stored.
 * The units of work are laid until the exercise of the dossier's date, the units consumed later
 * not being known. A plan stops on the day of disposal, or on an earlier day asked for (`through`:
 * the dotations up to the dossier's date, step 3.4b); an impairment moves the annuities after it.
 */
final readonly class FixedAssetPlans
{
    public function __construct(
        private ExerciseRepository $exercises,
        private RegulatoryReader $regulatory,
    ) {
    }

    public function plan(Dossier $dossier, FixedAsset $asset, ?\DateTimeImmutable $through = null): FixedAssetPlan
    {
        $stop = $asset->getDisposedOn();
        if (null !== $through && (null === $stop || $through < $stop)) {
            $stop = $through;
        }
        $impairments = [];
        foreach ($asset->impairmentBalances() as $day => $balance) {
            if ([] !== $impairments || $balance->isPositive()) {
                $impairments[(string) $day] = $balance;
            }
        }
        $method = $asset->getMethod();
        if (DepreciationMethod::None === $method) {
            return new FixedAssetPlan([]);
        }
        $coefficient = null;
        if (DepreciationMethod::Declining === $method || $asset->isFiscalDeclining()) {
            try {
                $coefficient = DecliningCoefficients::for($this->regulatory->at($asset->getAcquiredOn()), (int) $asset->getYears());
            } catch (MissingRegulatoryValue) {
                return new FixedAssetPlan([], fault: \sprintf('Le coefficient du dégressif en vigueur le %s manque aux paramètres réglementaires : le plan n’est pas calculé.', $asset->getAcquiredOn()->format('d/m/Y')));
            }
        }

        $years = max([$asset->getYears() ?? 0, ...array_column($asset->getComponents(), 'years')]);
        $from = min($asset->getInServiceOn(), $asset->getAcquiredOn()->modify('first day of this month'));
        $until = max($dossier->getDossierDate(), $asset->getInServiceOn()->modify('+'.$years.' years'));
        $exercises = array_reverse(array_map(static fn ($exercise): array => ['start' => $exercise->getStartsOn(), 'end' => $exercise->getEndsOn()], $this->exercises->findByDossier($dossier)));
        $windows = ExerciseWindows::cover($exercises, $from, $until);
        if (DepreciationMethod::Units === $method) {
            $windows = array_values(array_filter($windows, static fn (array $window): bool => $window['start'] <= $dossier->getDossierDate()));
        }

        if ([] !== $asset->getComponents()) {
            $components = [];
            foreach ($asset->getComponents() as $component) {
                $cost = Money::cents($component['cost']);
                $components[] = [
                    'label' => $component['label'],
                    'cost' => $cost,
                    'years' => $component['years'],
                    'rows' => DepreciationPlan::rows(new PlanInput(DepreciationMethod::Linear, $cost, Money::zero(), $asset->getAcquiredOn(), $asset->getInServiceOn(), $windows, $component['years'], disposedOn: $stop)),
                ];
            }

            return new FixedAssetPlan(DepreciationPlan::combine(array_column($components, 'rows')), $components);
        }

        return new FixedAssetPlan(DepreciationPlan::rows(new PlanInput(
            $method,
            $asset->getCost(),
            $asset->getResidualValue(),
            $asset->getAcquiredOn(),
            $asset->getInServiceOn(),
            $windows,
            $asset->getYears(),
            $coefficient,
            $asset->getTotalUnits(),
            $asset->getUnitsUsed(),
            $stop,
            $asset->isFiscalDeclining(),
            $impairments,
        )), coefficient: $coefficient);
    }

    /**
     * The exercises a units-of-work asset may be given its units for: from its putting into service
     * to the exercise of the dossier's date.
     *
     * @return list<array{start: \DateTimeImmutable, end: \DateTimeImmutable}>
     */
    public function unitWindows(Dossier $dossier, \DateTimeImmutable $inServiceOn): array
    {
        $exercises = array_reverse(array_map(static fn ($exercise): array => ['start' => $exercise->getStartsOn(), 'end' => $exercise->getEndsOn()], $this->exercises->findByDossier($dossier)));
        $windows = ExerciseWindows::cover($exercises, $inServiceOn, $dossier->getDossierDate());

        return array_values(array_filter($windows, static fn (array $window): bool => $window['end'] >= $inServiceOn && $window['start'] <= $dossier->getDossierDate()));
    }
}
