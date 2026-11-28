<?php

declare(strict_types=1);

namespace App\Service\Assets;

use App\Entity\Accounting\Account;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Accounting\AccountNature;
use App\Enum\Assets\AssetOrigin;
use App\Enum\Assets\DepreciationMethod;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Service\Accounting\Chart\AccountNumber;
use App\Service\Shared\DateValue;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;

/**
 * The one reading of a fixed asset's fiche (step 3.4), a pure function over three lookups - the
 * fiche and the demonstration's file go through it:
 *
 * - a code of 1 to 12 capitals or digits, unique in the dossier; a label; an asset account of the
 *   plan (class 2, neither 28 nor 29), and a depreciation account (28…) when it is depreciated;
 * - an acquisition day, a day of putting into service not before it; a price above zero, incidental
 *   costs and VAT never below; a supplier (a third party of kind supplier) only when it was bought;
 * - a method: none; linear or declining over 1 to 50 years (declining from 3: no coefficient
 *   below, art. 39 A CGI); units of work with the units the asset will give; a residual value
 *   below the cost, and none for the declining method, which goes to zero;
 * - a fiscal declining plan beside a linear one of 3 years at least (the dérogatoire);
 * - components (the simple approach): two at least, on a linear plan, their costs adding up to the
 *   cost, each over 1 to 50 years - the asset's own length is then theirs.
 */
final class FixedAssetRules
{
    public const int MAX_YEARS = 50;
    public const int MAX_COMPONENTS = 6;

    /**
     * @param callable(string): ?Account    $accountOf    by number
     * @param callable(string): ?ThirdParty $thirdPartyOf by code, then by auxiliary code
     * @param callable(string): ?string     $codeHolder   the label of another fixed asset of that code
     */
    public static function check(FixedAssetDraft $draft, callable $accountOf, callable $thirdPartyOf, callable $codeHolder): ?FixedAssetValues
    {
        $draft->errors = [];
        $draft->code = strtoupper((string) preg_replace('/\s+/u', '', $draft->code));
        $draft->label = trim((string) preg_replace('/\s+/u', ' ', $draft->label));
        $draft->account = AccountNumber::normalize($draft->account);
        $draft->depreciationAccount = AccountNumber::normalize($draft->depreciationAccount);
        $draft->supplier = strtoupper(trim($draft->supplier));
        $draft->document = trim($draft->document);

        if ('' === $draft->code) {
            $draft->errors['code'] = 'Indiquez le code de l’immobilisation.';
        } elseif (1 !== preg_match('/^[A-Z0-9]{1,12}$/', $draft->code)) {
            $draft->errors['code'] = 'Un code d’immobilisation compte de 1 à 12 lettres ou chiffres, sans accent ni espace (I01, MAT2026).';
        } elseif (null !== ($holder = $codeHolder($draft->code))) {
            $draft->errors['code'] = \sprintf('L’immobilisation %s existe déjà : %s.', $draft->code, $holder);
        }
        if ('' === $draft->label) {
            $draft->errors['label'] = 'Indiquez l’intitulé de l’immobilisation.';
        } elseif (mb_strlen($draft->label) > 120) {
            $draft->errors['label'] = 'Un intitulé tient en 120 caractères.';
        }
        $origin = AssetOrigin::tryFrom($draft->origin);
        if (null === $origin) {
            $draft->errors['origin'] = 'Choisissez l’origine de l’immobilisation.';
        }

        $account = null;
        if ('' === $draft->account) {
            $draft->errors['account'] = 'Indiquez le compte d’immobilisation.';
        } elseif (null === ($account = $accountOf($draft->account))) {
            $draft->errors['account'] = \sprintf('Le compte %s n’est pas dans le plan du dossier.', $draft->account);
        } elseif (AccountNature::FixedAsset !== AccountNature::of($draft->account)) {
            $draft->errors['account'] = 'Une immobilisation se tient sur un compte de la classe 2 (hors 28 et 29).';
        }

        $method = DepreciationMethod::tryFrom($draft->method);
        if (null === $method) {
            $draft->errors['method'] = 'Choisissez le mode d’amortissement.';
        }
        $depreciated = null !== $method && DepreciationMethod::None !== $method;
        $depreciationAccount = null;
        if (!$depreciated) {
            $draft->depreciationAccount = '';
        } elseif ('' === $draft->depreciationAccount) {
            $draft->errors['depreciationAccount'] = \sprintf('Indiquez le compte d’amortissement%s.', '' === $draft->account ? '' : ' ('.self::depreciationAccountFor($draft->account).' pour le compte '.$draft->account.')');
        } elseif (null === ($depreciationAccount = $accountOf($draft->depreciationAccount))) {
            $draft->errors['depreciationAccount'] = \sprintf('Le compte %s n’est pas dans le plan du dossier : créez-le dans le plan de comptes.', $draft->depreciationAccount);
        } elseif (!str_starts_with($draft->depreciationAccount, '28')) {
            $draft->errors['depreciationAccount'] = 'Un compte d’amortissement commence par 28.';
        }

        $acquiredOn = DateValue::parse($draft->acquiredOn);
        $inServiceOn = DateValue::parse($draft->inServiceOn);
        if (null === $acquiredOn) {
            $draft->errors['acquiredOn'] = 'Indiquez la date d’acquisition, au format JJ/MM/AAAA.';
        }
        if (null === $inServiceOn) {
            $draft->errors['inServiceOn'] = 'Indiquez la date de mise en service, au format JJ/MM/AAAA.';
        } elseif (null !== $acquiredOn && $inServiceOn < $acquiredOn) {
            $draft->errors['inServiceOn'] = 'Une immobilisation est mise en service le jour de son acquisition ou après.';
        }

        if (null === $draft->price || $draft->price <= 0) {
            $draft->errors['price'] = AssetOrigin::Production === $origin ? 'Indiquez le coût de production.' : 'Indiquez le prix d’acquisition hors taxes.';
        }
        if (null !== $draft->incidentalCosts && $draft->incidentalCosts < 0) {
            $draft->errors['incidentalCosts'] = 'Les frais accessoires ne sont jamais négatifs.';
        }
        if (null !== $draft->vat && $draft->vat < 0) {
            $draft->errors['vat'] = 'La TVA n’est jamais négative.';
        }
        if (null !== $draft->residualValue && $draft->residualValue < 0) {
            $draft->errors['residualValue'] = 'La valeur résiduelle n’est jamais négative.';
        }
        $cost = Money::cents(max(0, $draft->price ?? 0) + max(0, $draft->incidentalCosts ?? 0));
        $residual = Money::cents(max(0, $draft->residualValue ?? 0));
        if (!isset($draft->errors['residualValue']) && $residual->isPositive()) {
            if (!$depreciated || DepreciationMethod::Declining === $method) {
                $draft->errors['residualValue'] = DepreciationMethod::Declining === $method ? 'Le dégressif amortit jusqu’à zéro : pas de valeur résiduelle.' : 'Une immobilisation non amortie n’a pas de valeur résiduelle.';
            } elseif ($residual->compareTo($cost) >= 0) {
                $draft->errors['residualValue'] = \sprintf('La valeur résiduelle reste sous le coût d’entrée (%s).', FrenchFormat::amount($cost));
            }
        }

        $supplier = null;
        if ('' !== $draft->supplier) {
            $supplier = $thirdPartyOf($draft->supplier);
            if (AssetOrigin::Production === $origin) {
                $draft->errors['supplier'] = 'Une production immobilisée n’a pas de fournisseur.';
            } elseif (null === $supplier) {
                $draft->errors['supplier'] = \sprintf('Le tiers %s n’existe pas dans le dossier.', $draft->supplier);
            } elseif (ThirdPartyKind::Supplier !== $supplier->getKind()) {
                $draft->errors['supplier'] = \sprintf('Le tiers %s n’est pas un fournisseur.', $draft->supplier);
            }
        }

        $components = [];
        foreach ($draft->components as $component) {
            $label = trim((string) preg_replace('/\s+/u', ' ', $component['label']));
            if ('' === $label && null === $component['cost'] && '' === trim($component['years'])) {
                continue;
            }
            $at = 'component.'.\count($components).'.';
            $years = self::wholeNumber($component['years']);
            if ('' === $label) {
                $draft->errors[$at.'label'] = 'Nommez le composant.';
            }
            if (null === $component['cost'] || $component['cost'] <= 0) {
                $draft->errors[$at.'cost'] = 'Indiquez la valeur du composant.';
            }
            if (null === $years || $years < 1 || $years > self::MAX_YEARS) {
                $draft->errors[$at.'years'] = \sprintf('Une durée d’utilisation compte de 1 à %d ans.', self::MAX_YEARS);
            }
            $components[] = ['label' => $label, 'cost' => max(0, $component['cost'] ?? 0), 'years' => $years ?? 0];
        }
        $draft->components = array_map(static fn (array $component): array => ['label' => $component['label'], 'cost' => $component['cost'], 'years' => (string) $component['years']], $components);

        $years = null;
        $totalUnits = null;
        $unitsUsed = [];
        if ([] !== $components) {
            if (DepreciationMethod::Linear !== $method) {
                $draft->errors['components'] = 'Les composants s’amortissent en linéaire, chacun sur sa durée.';
            } elseif (\count($components) < 2) {
                $draft->errors['components'] = 'Une approche par composants en compte deux au moins (la structure et un composant).';
            } elseif (\count($components) > self::MAX_COMPONENTS) {
                $draft->errors['components'] = \sprintf('Une immobilisation compte %d composants au plus.', self::MAX_COMPONENTS);
            } elseif (!isset($draft->errors['price']) && array_sum(array_column($components, 'cost')) !== $cost->cents) {
                $draft->errors['components'] = \sprintf('Les composants font %s : ils font ensemble le coût d’entrée, %s.', FrenchFormat::amount(Money::cents(array_sum(array_column($components, 'cost')))), FrenchFormat::amount($cost));
            }
            if ($residual->isPositive() && !isset($draft->errors['residualValue'])) {
                $draft->errors['residualValue'] = 'Une immobilisation par composants n’a pas de valeur résiduelle.';
            }
            if ($draft->fiscalDeclining) {
                $draft->errors['fiscalDeclining'] = 'Le plan fiscal dégressif ne se tient pas sur des composants.';
            }
            $draft->years = '';
        } elseif (DepreciationMethod::Linear === $method || DepreciationMethod::Declining === $method) {
            $years = self::wholeNumber($draft->years);
            $minimum = DepreciationMethod::Declining === $method ? 3 : 1;
            if (null === $years || $years < $minimum || $years > self::MAX_YEARS) {
                $draft->errors['years'] = DepreciationMethod::Declining === $method && null !== $years && $years >= 1 && $years < 3
                    ? 'Le dégressif demande une durée d’utilisation de 3 ans au moins.'
                    : \sprintf('Indiquez la durée d’utilisation, de %d à %d ans.', $minimum, self::MAX_YEARS);
            }
        } elseif (DepreciationMethod::Units === $method) {
            $totalUnits = self::wholeNumber($draft->totalUnits);
            if (null === $totalUnits || $totalUnits < 1) {
                $draft->errors['totalUnits'] = 'Indiquez le nombre d’unités d’œuvre que l’immobilisation donnera.';
            }
            foreach ($draft->unitsUsed as $day => $typed) {
                if ('' === trim($typed)) {
                    continue;
                }
                $units = self::wholeNumber($typed);
                if (null === $units || null === DateValue::parse($day)) {
                    $draft->errors['units'] = 'Les unités consommées s’écrivent en nombres entiers.';
                    continue;
                }
                $unitsUsed[$day] = $units;
            }
            ksort($unitsUsed);
        }
        if (DepreciationMethod::Units !== $method) {
            $draft->totalUnits = '';
            $draft->unitsUsed = [];
        }
        if (null === $method || DepreciationMethod::None === $method || DepreciationMethod::Units === $method) {
            $draft->years = '';
        }
        if ($draft->fiscalDeclining && [] === $components && (DepreciationMethod::Linear !== $method || (null !== $years && $years < 3))) {
            $draft->errors['fiscalDeclining'] = 'Le plan fiscal dégressif accompagne un amortissement linéaire de 3 ans au moins.';
        }

        if ([] !== $draft->errors || null === $origin || null === $account || null === $method || null === $acquiredOn || null === $inServiceOn) {
            return null;
        }

        return new FixedAssetValues(
            $draft->code,
            $draft->label,
            $origin,
            $account,
            $depreciationAccount,
            $acquiredOn,
            $inServiceOn,
            Money::cents($draft->price ?? 0),
            Money::cents(max(0, $draft->incidentalCosts ?? 0)),
            Money::cents(max(0, $draft->vat ?? 0)),
            $supplier,
            $draft->document,
            $residual,
            $method,
            $years,
            $totalUnits,
            $unitsUsed,
            $draft->fiscalDeclining && [] === $components,
            $components,
        );
    }

    /** The depreciation account proposed for an asset account: « 2 » becomes « 28 » (215400 → 281540). */
    public static function depreciationAccountFor(string $number): string
    {
        return substr('28'.substr($number, 1), 0, \strlen($number));
    }

    private static function wholeNumber(string $typed): ?int
    {
        $typed = str_replace([' ', "\u{202F}", "\u{00A0}"], '', trim($typed));

        return 1 === preg_match('/^\d{1,9}$/', $typed) ? (int) $typed : null;
    }
}
