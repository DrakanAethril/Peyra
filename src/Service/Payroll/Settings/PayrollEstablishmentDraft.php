<?php

declare(strict_types=1);

namespace App\Service\Payroll\Settings;

use App\Entity\Payroll\PayrollEstablishment;
use App\Service\Shared\FrenchFormat;

/** The establishment of the payroll as a person typed it, with the error of each field. */
final class PayrollEstablishmentDraft
{
    public string $headcount = '';
    public string $workAccidentRate = '';
    /** Empty when no mobility payment is due. */
    public string $mobilityRate = '';
    public string $monthlyHours = '';

    /** @var array<string, string> by field */
    public array $errors = [];

    public static function of(PayrollEstablishment $establishment): self
    {
        $draft = new self();
        $draft->headcount = FrenchFormat::number($establishment->getHeadcount());
        $draft->workAccidentRate = FrenchFormat::number($establishment->getWorkAccidentRate());
        $draft->mobilityRate = null === $establishment->getMobilityRate() ? '' : FrenchFormat::number($establishment->getMobilityRate());
        $draft->monthlyHours = FrenchFormat::number($establishment->getMonthlyHours());

        return $draft;
    }

    public function error(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }
}
