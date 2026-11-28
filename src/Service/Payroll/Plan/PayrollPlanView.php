<?php

declare(strict_types=1);

namespace App\Service\Payroll\Plan;

use App\Entity\Dossier\Dossier;
use App\Entity\Payroll\PayrollItem;
use App\Repository\Payroll\PayrollEstablishmentRepository;
use App\Repository\Payroll\PayrollItemRepository;
use App\Service\Regulatory\RegulatoryReader;

/**
 * The plan de paie as its list shows it: the rubriques by family, each rate read at the dossier's
 * date with its source (PayrollRateReading). Inside the dossier's context.
 *
 * @phpstan-type Reading array{text: string, source: string, missing: bool, figure: bool}
 * @phpstan-type PlanRow array{item: PayrollItem, employee: Reading, employer: Reading}
 */
final readonly class PayrollPlanView
{
    public function __construct(
        private PayrollItemRepository $items,
        private PayrollEstablishmentRepository $establishments,
        private RegulatoryReader $regulatory,
    ) {
    }

    /** @return array<string, list<PlanRow>> by family (PayrollItemKind's value), in the payslip's order */
    public function families(Dossier $dossier): array
    {
        $snapshot = $this->regulatory->at($dossier->getDossierDate());
        $establishment = $this->establishments->findOfDossier($dossier);
        $families = [];
        foreach ($this->items->findPlan($dossier) as $item) {
            $families[$item->getKind()->value][] = [
                'item' => $item,
                'employee' => PayrollRateReading::of($item->getEmployeeRate(), $snapshot, $establishment),
                'employer' => PayrollRateReading::of($item->getEmployerRate(), $snapshot, $establishment),
            ];
        }

        return $families;
    }

    /** @return array{employee: Reading, employer: Reading} the readings of one rubrique, for its fiche */
    public function readings(Dossier $dossier, PayrollItem $item): array
    {
        $snapshot = $this->regulatory->at($dossier->getDossierDate());
        $establishment = $this->establishments->findOfDossier($dossier);

        return [
            'employee' => PayrollRateReading::of($item->getEmployeeRate(), $snapshot, $establishment),
            'employer' => PayrollRateReading::of($item->getEmployerRate(), $snapshot, $establishment),
        ];
    }
}
