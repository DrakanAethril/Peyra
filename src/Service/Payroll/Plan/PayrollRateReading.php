<?php

declare(strict_types=1);

namespace App\Service\Payroll\Plan;

use App\Entity\Payroll\PayrollEstablishment;
use App\Entity\Payroll\PayrollRate;
use App\Enum\Payroll\PayrollRateSource;
use App\Service\Regulatory\MissingRegulatoryValue;
use App\Service\Regulatory\RegulatorySnapshot;
use App\Service\Shared\FrenchFormat;

/**
 * A rate of the plan de paie as the screens show it (step 4.1), pure: the value it reads on the
 * dossier's date - « 6,9 % » for a regulatory rate in force, « 5,5 € » for one in euros, the
 * dossier's own percent or amount, the establishment's work accident rate - and where it comes
 * from, so that every figure carries its source. What the payslip brings (the hourly rate of the
 * contract, the employee's withholding rate, the coefficient of the reduction) is named, not
 * valued; a regulatory value that does not read is said so (`missing`).
 */
final class PayrollRateReading
{
    /** @return array{text: string, source: string, missing: bool} */
    public static function of(PayrollRate $rate, RegulatorySnapshot $snapshot, ?PayrollEstablishment $establishment): array
    {
        $source = $rate->getSource();
        $key = $rate->getReferenceKey();
        if (null !== $key) {
            $where = $key.(null === $rate->getReferencePath() ? '' : ' › '.str_replace('.', ' › ', $rate->getReferencePath()));
            try {
                $decimal = $snapshot->decimal($key, ...$rate->pathSegments());
                $unit = $snapshot->value($key)->getUnit();
                $value = str_starts_with($unit, 'percent') ? FrenchFormat::percent($decimal) : FrenchFormat::number($decimal).(str_starts_with($unit, 'EUR') ? "\u{a0}€" : '');
                $text = PayrollRateSource::HourlyRateWithPremium === $source ? 'taux horaire + '.$value : $value;

                return ['text' => $text, 'source' => $source->label().' : '.$where, 'missing' => false];
            } catch (MissingRegulatoryValue) {
                return ['text' => 'non lue', 'source' => $source->label().' : '.$where.', sans valeur au '.FrenchFormat::date($snapshot->day), 'missing' => true];
            }
        }

        return match ($source) {
            PayrollRateSource::None => ['text' => '', 'source' => '', 'missing' => false],
            PayrollRateSource::Fixed => ['text' => FrenchFormat::percent((string) $rate->getPercent()), 'source' => $source->label(), 'missing' => false],
            PayrollRateSource::Amount => ['text' => null === $rate->getAmount() ? '' : FrenchFormat::amountInEuros($rate->getAmount()), 'source' => $source->label(), 'missing' => false],
            PayrollRateSource::WorkAccident => null === $establishment
                ? ['text' => 'à saisir', 'source' => $source->label(), 'missing' => true]
                : ['text' => FrenchFormat::percent($establishment->getWorkAccidentRate()), 'source' => $source->label(), 'missing' => false],
            PayrollRateSource::Mobility => self::mobility($establishment?->getMobilityRate(), $source),
            PayrollRateSource::HourlyRate => ['text' => 'taux horaire', 'source' => $source->label(), 'missing' => false],
            PayrollRateSource::Entered => ['text' => 'saisi', 'source' => $source->label(), 'missing' => false],
            PayrollRateSource::WithholdingRate => ['text' => 'taux du salarié', 'source' => $source->label(), 'missing' => false],
            PayrollRateSource::GeneralReduction => ['text' => 'coefficient', 'source' => $source->label(), 'missing' => false],
            default => ['text' => '', 'source' => $source->label(), 'missing' => false],
        };
    }

    /** @return array{text: string, source: string, missing: bool} */
    private static function mobility(?string $rate, PayrollRateSource $source): array
    {
        return ['text' => null === $rate ? 'non dû' : FrenchFormat::percent($rate), 'source' => $source->label(), 'missing' => false];
    }
}
