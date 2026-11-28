<?php

declare(strict_types=1);

namespace App\Service\Assets\Disposal;

use App\Entity\Assets\FixedAsset;
use App\Enum\Assets\DisposalKind;
use App\Service\Shared\DateValue;

/** A fixed asset's way out as a person typed it, before DisposalRules checks it, with the error of each field. */
final class DisposalDraft
{
    public string $kind = 'sale';
    /** ISO text (Px:DateInput's hidden field). */
    public string $disposedOn = '';
    /** Cents (Px:AmountInput's hidden field); null when left empty. */
    public ?int $price = null;
    public ?int $vat = null;
    /** The buyer's code or auxiliary code. */
    public string $buyer = '';

    /** @var array<string, string> by field */
    public array $errors = [];

    public static function of(FixedAsset $asset, \DateTimeImmutable $dossierDate): self
    {
        $draft = new self();
        if (null === $asset->getDisposedOn()) {
            $draft->disposedOn = $dossierDate->format('Y-m-d');

            return $draft;
        }
        $draft->kind = ($asset->getDisposalKind() ?? DisposalKind::Sale)->value;
        $draft->disposedOn = $asset->getDisposedOn()->format('Y-m-d');
        $draft->price = $asset->getDisposalPrice()->cents;
        $draft->vat = $asset->getDisposalVat()->cents;
        $draft->buyer = $asset->getBuyer()?->getCode() ?? '';

        return $draft;
    }

    public function date(): ?\DateTimeImmutable
    {
        return DateValue::parse($this->disposedOn);
    }

    public function error(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }
}
