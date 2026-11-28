<?php

declare(strict_types=1);

namespace App\Service\Assets\Disposal;

use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Assets\DisposalKind;
use App\Service\Shared\DateValue;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The one reading of a fixed asset's way out (step 3.4b), a pure function over the asset's facts and a
 * lookup of third parties:
 *
 * - a sale (« cession ») or a scrapping (« mise au rebut »), on a day not before the acquisition nor
 *   before the last day the dotations were passed for it (they would have to be taken back first);
 * - a sale has a price above zero, maybe a buyer (a third party of the dossier); the VAT on it never
 *   negative - left empty, it is the normal rate on the price when the asset's own VAT was deducted
 *   (a used movable asset whose VAT was deducted is sold with VAT, CGI art. 261, 3-1° a), else none;
 *   typed 0, the sale goes without VAT and the VAT deducted is regularised (VatRegularisation);
 * - a scrapping has no price, no VAT, no buyer.
 */
final class DisposalRules
{
    /** @param callable(string): ?ThirdParty $thirdPartyOf by code, then by auxiliary code */
    public static function check(
        DisposalDraft $draft,
        \DateTimeImmutable $acquiredOn,
        ?\DateTimeImmutable $lastPassed,
        Money $assetVat,
        ?BigDecimal $normalRate,
        callable $thirdPartyOf,
    ): ?DisposalValues {
        $draft->errors = [];
        $draft->buyer = strtoupper(trim($draft->buyer));
        $kind = DisposalKind::tryFrom($draft->kind);
        if (null === $kind) {
            $draft->errors['kind'] = 'Choisissez entre une cession et une mise au rebut.';
        }
        $on = DateValue::parse($draft->disposedOn);
        if (null === $on) {
            $draft->errors['disposedOn'] = 'Indiquez la date de sortie, au format JJ/MM/AAAA.';
        } elseif ($on < $acquiredOn) {
            $draft->errors['disposedOn'] = \sprintf('Une immobilisation sort de l’actif après son acquisition, le %s.', FrenchFormat::date($acquiredOn));
        } elseif (null !== $lastPassed && $on < $lastPassed) {
            $draft->errors['disposedOn'] = \sprintf('Les dotations sont passées jusqu’au %s : la sortie se date ce jour-là au plus tôt.', FrenchFormat::date($lastPassed));
        }

        $price = Money::zero();
        $vat = Money::zero();
        $buyer = null;
        if (DisposalKind::Scrapping === $kind) {
            $draft->price = null;
            $draft->vat = null;
            $draft->buyer = '';
        } elseif (DisposalKind::Sale === $kind) {
            if (null === $draft->price || $draft->price <= 0) {
                $draft->errors['price'] = 'Indiquez le prix de cession hors taxes.';
            } else {
                $price = Money::cents($draft->price);
            }
            if (null !== $draft->vat && $draft->vat < 0) {
                $draft->errors['vat'] = 'La TVA n’est jamais négative.';
            } elseif (null !== $draft->vat) {
                $vat = Money::cents($draft->vat);
            } elseif ($assetVat->isPositive() && null !== $normalRate && $price->isPositive()) {
                $vat = $price->times($normalRate, RoundingMode::HalfUp);
                $draft->vat = $vat->cents;
            }
            if ('' !== $draft->buyer) {
                $buyer = $thirdPartyOf($draft->buyer);
                if (null === $buyer) {
                    $draft->errors['buyer'] = \sprintf('Le tiers %s n’existe pas dans le dossier.', $draft->buyer);
                }
            }
        }

        if ([] !== $draft->errors || null === $on) {
            return null;
        }

        return new DisposalValues($kind, $on, $price, $vat, $buyer);
    }
}
