<?php

declare(strict_types=1);

namespace App\Service\Assets;

use App\Entity\Assets\FixedAsset;
use App\Service\Shared\DateValue;

/** A fixed asset's fiche as a person typed it, before FixedAssetRules checks it, with the error of each field. */
final class FixedAssetDraft
{
    public string $code = '';
    public string $label = '';
    public string $origin = 'purchase';
    /** Numbers as typed (the account picker fills them). */
    public string $account = '';
    public string $depreciationAccount = '';
    /** Days as ISO texts (Px:DateInput's hidden field). */
    public string $acquiredOn = '';
    public string $inServiceOn = '';
    /** Amounts in cents (Px:AmountInput's hidden field); null when left empty. */
    public ?int $price = null;
    public ?int $incidentalCosts = null;
    public ?int $vat = null;
    /** The supplier's code or auxiliary code. */
    public string $supplier = '';
    public string $document = '';
    public ?int $residualValue = null;
    public string $method = 'linear';
    public string $years = '';
    public string $totalUnits = '';
    public bool $fiscalDeclining = false;
    /** @var list<array{label: string, cost: ?int, years: string}> */
    public array $components = [];
    /** @var array<string, string> the units consumed as typed, by the last day of an exercise */
    public array $unitsUsed = [];
    /** @var array<string, ?int> the impairment's balance in cents as typed, by the last day of an exercise */
    public array $impairments = [];

    /** @var array<string, string> by field */
    public array $errors = [];

    public static function of(FixedAsset $asset): self
    {
        $draft = new self();
        $draft->code = $asset->getCode();
        $draft->label = $asset->getLabel();
        $draft->origin = $asset->getOrigin()->value;
        $draft->account = $asset->getAccount()->getNumber();
        $draft->depreciationAccount = $asset->getDepreciationAccount()?->getNumber() ?? '';
        $draft->acquiredOn = $asset->getAcquiredOn()->format('Y-m-d');
        $draft->inServiceOn = $asset->getInServiceOn()->format('Y-m-d');
        $draft->price = $asset->getPrice()->cents;
        $draft->incidentalCosts = $asset->getIncidentalCosts()->cents;
        $draft->vat = $asset->getVat()->cents;
        $draft->supplier = $asset->getSupplier()?->getCode() ?? '';
        $draft->document = $asset->getDocument();
        $draft->residualValue = $asset->getResidualValue()->cents;
        $draft->method = $asset->getMethod()->value;
        $draft->years = null === $asset->getYears() ? '' : (string) $asset->getYears();
        $draft->totalUnits = null === $asset->getTotalUnits() ? '' : (string) $asset->getTotalUnits();
        $draft->fiscalDeclining = $asset->isFiscalDeclining();
        $draft->components = array_map(static fn (array $component): array => ['label' => $component['label'], 'cost' => $component['cost'], 'years' => (string) $component['years']], $asset->getComponents());
        $draft->unitsUsed = array_map(static fn (int $units): string => (string) $units, $asset->getUnitsUsed());
        $draft->impairments = $asset->getImpairments();

        return $draft;
    }

    /** A day of the draft as a date, for the date fields: `acquiredOn` or `inServiceOn`. */
    public function date(string $field): ?\DateTimeImmutable
    {
        return DateValue::parse('acquiredOn' === $field ? $this->acquiredOn : $this->inServiceOn);
    }

    public function error(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }
}
