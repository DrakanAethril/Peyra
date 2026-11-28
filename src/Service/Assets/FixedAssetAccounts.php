<?php

declare(strict_types=1);

namespace App\Service\Assets;

use App\Entity\Accounting\Account;

/**
 * The accounts of the fixed assets' entries (step 3.4b), pure over a lookup in the dossier's plan:
 * the plan comptable général's own accounts, each taken at the nearest number the plan holds (the
 * dossier's plan is the reference chart at six digits, maybe extended - 281540 is found when the plan
 * has it, else 281500):
 *
 * - dotations to depreciation: 68111 for an intangible asset (20), 68112 for a tangible one (21, 23);
 * - impairment: 68161 / 68162 and their reprises 78161 / 78162, 68662 / 78662 for a financial
 *   asset (26, 27); the impairment account « 29 » + the asset's number (215400 → 291540, 291500);
 * - dérogatoire: 68725, 145 and 78725;
 * - a way out: the net book value on 657 and the price on 757 (PCG from 2025), 675 and 775 in a plan
 *   that does not know them; the price owed on 462, the VAT on it to 44571;
 * - an acquisition's VAT on 44562, owed to the supplier's account or 404; a production credited to 721
 *   or 722;
 * - VAT given back on a way out without VAT (« régularisation ») charged on 658, owed with the VAT
 *   collected (44571: it is VAT due, line 15 of the CA3).
 */
final class FixedAssetAccounts
{
    public const string DEROGATORY = '145000';
    public const string DEROGATORY_DOTATION = '687250';
    public const string DEROGATORY_REPRISE = '787250';
    public const string RECEIVABLE = '462000';
    public const string COLLECTED_VAT = '445710';
    public const string ASSET_VAT = '445620';
    public const string ASSET_SUPPLIERS = '404000';
    public const string VAT_REGULARISATION = '658000';

    /** @param \Closure(string): ?Account $accountOf by number */
    public function __construct(private \Closure $accountOf)
    {
    }

    public function dotation(string $asset): string
    {
        return $this->nearest(self::intangible($asset) ? '681110' : '681120');
    }

    public function impairment(string $asset): string
    {
        return $this->nearest(substr('29'.substr($asset, 1), 0, \strlen($asset)));
    }

    public function impairmentDotation(string $asset): string
    {
        return $this->nearest(self::financial($asset) ? '686620' : (self::intangible($asset) ? '681610' : '681620'));
    }

    public function impairmentReprise(string $asset): string
    {
        return $this->nearest(self::financial($asset) ? '786620' : (self::intangible($asset) ? '781610' : '781620'));
    }

    /** The net book value of an asset gone: 657 where the plan has it, else 675. */
    public function bookValueOut(): string
    {
        return $this->within('657000', 3) ?? $this->nearest('675000');
    }

    /** The sale price of an asset: 757 where the plan has it, else 775. */
    public function salePrice(): string
    {
        return $this->within('757000', 3) ?? $this->nearest('775000');
    }

    public function production(string $asset): string
    {
        return $this->nearest(self::intangible($asset) ? '721000' : '722000');
    }

    /** The account itself when the plan has it or one above it down to two digits, else the number as is. */
    public function nearest(string $number): string
    {
        return $this->within($number, 2) ?? $number;
    }

    private function within(string $number, int $shortest): ?string
    {
        for ($length = \strlen($number); $length >= $shortest; --$length) {
            $candidate = str_pad(substr($number, 0, $length), \strlen($number), '0');
            if (null !== ($this->accountOf)($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function intangible(string $asset): bool
    {
        return str_starts_with($asset, '20');
    }

    private static function financial(string $asset): bool
    {
        return str_starts_with($asset, '26') || str_starts_with($asset, '27');
    }
}
