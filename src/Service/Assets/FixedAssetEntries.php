<?php

declare(strict_types=1);

namespace App\Service\Assets;

use App\Service\Accounting\Entry\GridLine;
use App\Service\Assets\Disposal\DisposalCalculation;
use App\Service\Assets\Dotation\DotationLine;
use App\Service\Document\Processing\PieceProposal;
use App\Service\Shared\Money;

/**
 * The entries the fixed assets ask for (step 3.4b), pure - what AccountingGateway is handed, in the
 * grid's own shape (PieceProposal), from amounts the plan and the fiche give:
 *
 * - **an acquisition**: the asset debited with its cost, the VAT on 44562, the supplier (its auxiliary
 *   account, else 404) credited with both, in the purchases journal; **a production**: the asset
 *   debited, 721 or 722 credited, in the general journal;
 * - **the dotations** up to a day, account by account: the dotation account debited and the
 *   depreciation account credited, the dérogatoire (68725 / 145, or 145 / 78725 for a reprise), the
 *   impairment's movement (68162 / 29…, or 29… / 78162);
 * - **a way out**: the dotation up to its day, the depreciation written back on 28 and the cost out of
 *   the asset account - the difference on 657 -, the impairment and the dérogatoire reversed, the price
 *   owed by the buyer (462) with its VAT, the VAT given back. Its lines stay one by one, as a student
 *   writes them.
 *
 * A negative amount goes on the other side; a zero amount makes no line.
 */
final class FixedAssetEntries
{
    public static function acquisition(string $journal, \DateTimeImmutable $on, string $document, string $label, string $asset, Money $cost, Money $vat, string $supplierCell, string $supplierThirdParty): PieceProposal
    {
        return new PieceProposal($journal, $on->format('Y-m-d'), $document, $label, self::lines([
            self::line($asset, '', $cost, true),
            self::line(FixedAssetAccounts::ASSET_VAT, '', $vat, true),
            self::line($supplierCell, $supplierThirdParty, $cost->plus($vat), false),
        ]));
    }

    public static function production(string $journal, \DateTimeImmutable $on, string $document, string $label, string $asset, Money $cost, string $productionAccount): PieceProposal
    {
        return new PieceProposal($journal, $on->format('Y-m-d'), $document, $label, self::lines([
            self::line($asset, '', $cost, true),
            self::line($productionAccount, '', $cost, false),
        ]));
    }

    /** @param list<DotationLine> $rows */
    public static function dotations(string $journal, \DateTimeImmutable $on, string $document, string $label, array $rows): PieceProposal
    {
        /** @var array<string, Money> $debits */
        $debits = [];
        /** @var array<string, Money> $credits */
        $credits = [];
        $add = static function (array &$side, string $account, Money $amount): void {
            $side[$account] = ($side[$account] ?? Money::zero())->plus($amount);
        };
        foreach ($rows as $row) {
            if (null !== $row->depreciationAccount) {
                $add($debits, $row->dotationAccount, $row->dotation);
                $add($credits, $row->depreciationAccount, $row->dotation);
            }
            if (null !== $row->derogatory && $row->derogatory->isPositive()) {
                $add($debits, FixedAssetAccounts::DEROGATORY_DOTATION, $row->derogatory);
                $add($credits, FixedAssetAccounts::DEROGATORY, $row->derogatory);
            } elseif (null !== $row->derogatory && $row->derogatory->isNegative()) {
                $add($debits, FixedAssetAccounts::DEROGATORY, $row->derogatory->abs());
                $add($credits, FixedAssetAccounts::DEROGATORY_REPRISE, $row->derogatory->abs());
            }
            if (null !== $row->impairment && $row->impairment->isPositive()) {
                $add($debits, $row->impairmentDotationAccount, $row->impairment);
                $add($credits, $row->impairmentAccount, $row->impairment);
            } elseif (null !== $row->impairment && $row->impairment->isNegative()) {
                $add($debits, $row->impairmentAccount, $row->impairment->abs());
                $add($credits, $row->impairmentRepriseAccount, $row->impairment->abs());
            }
        }
        ksort($debits, \SORT_STRING);
        ksort($credits, \SORT_STRING);
        $lines = [];
        foreach ($debits as $account => $amount) {
            $lines[] = self::line((string) $account, '', $amount, true);
        }
        foreach ($credits as $account => $amount) {
            $lines[] = self::line((string) $account, '', $amount, false);
        }

        return new PieceProposal($journal, $on->format('Y-m-d'), $document, $label, self::lines($lines));
    }

    /**
     * @param array{asset: string, depreciation: ?string, dotation: string, impairment: string, impairmentReprise: string, bookValueOut: string, salePrice: string} $accounts
     */
    public static function disposal(string $journal, \DateTimeImmutable $on, string $document, string $label, DisposalCalculation $calculation, array $accounts, string $buyerCell, string $buyerThirdParty): PieceProposal
    {
        $lines = [];
        if (null !== $accounts['depreciation']) {
            $lines[] = self::line($accounts['dotation'], '', $calculation->complement, true);
            $lines[] = self::line($accounts['depreciation'], '', $calculation->complement, false);
            $lines[] = self::line($accounts['depreciation'], '', $calculation->depreciation(), true);
        }
        $lines[] = self::line($accounts['bookValueOut'], '', $calculation->bookValueOut(), true);
        $lines[] = self::line($accounts['asset'], '', $calculation->cost, false);
        $lines[] = self::line($accounts['impairment'], '', $calculation->impairment, true);
        $lines[] = self::line($accounts['impairmentReprise'], '', $calculation->impairment, false);
        $lines[] = self::line(FixedAssetAccounts::DEROGATORY, '', $calculation->derogatory, true);
        $lines[] = self::line(FixedAssetAccounts::DEROGATORY_REPRISE, '', $calculation->derogatory, false);
        $lines[] = self::line($buyerCell, $buyerThirdParty, $calculation->receivable(), true);
        $lines[] = self::line($accounts['salePrice'], '', $calculation->price, false);
        $lines[] = self::line(FixedAssetAccounts::COLLECTED_VAT, '', $calculation->vat, false);
        $lines[] = self::line(FixedAssetAccounts::VAT_REGULARISATION, '', $calculation->vatRegularisation, true);
        $lines[] = self::line(FixedAssetAccounts::COLLECTED_VAT, '', $calculation->vatRegularisation, false);

        return new PieceProposal($journal, $on->format('Y-m-d'), $document, $label, self::lines($lines));
    }

    /**
     * @param list<?GridLine> $lines
     *
     * @return list<GridLine>
     */
    private static function lines(array $lines): array
    {
        return array_values(array_filter($lines));
    }

    private static function line(string $account, string $thirdParty, Money $amount, bool $debit): ?GridLine
    {
        if ($amount->isZero()) {
            return null;
        }
        if ($amount->isNegative()) {
            [$debit, $amount] = [!$debit, $amount->abs()];
        }

        return new GridLine($account, $thirdParty, '', $debit ? $amount->cents : 0, $debit ? 0 : $amount->cents, null);
    }
}
