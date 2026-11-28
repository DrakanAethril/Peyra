<?php

declare(strict_types=1);

namespace App\Service\Document\Processing;

use App\Enum\Accounting\JournalType;
use App\Enum\Document\DocumentType;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Service\Accounting\Entry\GridLine;
use App\Service\Document\VatLine;
use App\Service\Shared\Money;

/**
 * The entry proposed from a pièce's read data (design-ux.md § 3.5): a pure function, which never
 * reads anything but what it is given - so it proposes what the pièce says, its faults included.
 *
 * - A purchase invoice: the expense (the bases of the VAT breakdown, else the total excluding tax)
 *   on the account the supplier's previous entries used, else left for the student; the VAT of
 *   each rate on 445660; the supplier credited with the total including tax and its due date.
 * - A sales invoice: the customer debited, the VAT of each rate on 445710, the revenue credited.
 * - A credit note: the same entry, sides reversed - a supplier's is a purchase, a customer's a sale.
 *
 * Nothing is proposed for another nature, nor without a third party kept on an account, a date or
 * the total including tax.
 */
final class PieceProposals
{
    /** TVA déductible sur autres biens et services, TVA collectée (PCG). */
    public const string DEDUCTIBLE_VAT = '445660';
    public const string COLLECTED_VAT = '445710';

    /** Where a pièce's entry goes: a purchase or a sale; null when its nature calls for no proposal. */
    public static function side(DocumentType $type, ?ThirdPartyKind $kind): ?JournalType
    {
        return match ($type) {
            DocumentType::PurchaseInvoice => JournalType::Purchases,
            DocumentType::SalesInvoice => JournalType::Sales,
            DocumentType::CreditNote => match ($kind) {
                ThirdPartyKind::Supplier => JournalType::Purchases,
                ThirdPartyKind::Customer => JournalType::Sales,
                default => null,
            },
            default => null,
        };
    }

    /**
     * @param list<VatLine> $vatLines
     * @param string|null   $journal      the code of the dossier's journal for that side
     * @param string|null   $usualAccount the expense or revenue account the third party's previous entries used
     */
    public static function propose(
        DocumentType $type,
        ?PieceParty $party,
        ?string $number,
        ?\DateTimeImmutable $date,
        ?\DateTimeImmutable $dueDate,
        array $vatLines,
        ?Money $totalExcludingTax,
        ?Money $totalIncludingTax,
        ?string $journal,
        ?string $usualAccount,
        string $basis = '',
    ): ?PieceProposal {
        $side = self::side($type, $party?->kind);
        if (null === $side || null === $party || null === $date || null === $totalIncludingTax || null === $journal) {
            return null;
        }
        $purchase = JournalType::Purchases === $side;
        // An invoice puts the expense on the debit (a revenue on the credit); a credit note reverses.
        $expenseOnDebit = $purchase !== (DocumentType::CreditNote === $type);

        $base = [] === $vatLines
            ? ($totalExcludingTax ?? Money::zero())
            : Money::sum(array_map(static fn (VatLine $line): Money => $line->base, $vatLines));
        $lines = [];
        if (!$base->isZero()) {
            $lines[] = self::line($usualAccount ?? '', '', $base, $expenseOnDebit, null);
        }
        foreach ($vatLines as $line) {
            if (!$line->vat->isZero()) {
                $lines[] = self::line($purchase ? self::DEDUCTIBLE_VAT : self::COLLECTED_VAT, '', $line->vat, $expenseOnDebit, null);
            }
        }
        $lines[] = self::line($party->accountCell, $party->thirdPartyCell, $totalIncludingTax, !$expenseOnDebit, $dueDate?->format('Y-m-d'));

        $reference = trim($number ?? '');

        return new PieceProposal(
            $journal,
            $date->format('Y-m-d'),
            $reference,
            mb_substr(trim($party->name.' '.$reference), 0, 250),
            $lines,
            $basis,
        );
    }

    /** A line on the debit or the credit; a negative amount (a discount read as such) changes side. */
    private static function line(string $account, string $thirdParty, Money $amount, bool $debit, ?string $due): GridLine
    {
        if ($amount->isNegative()) {
            $debit = !$debit;
            $amount = $amount->abs();
        }

        return new GridLine($account, $thirdParty, '', $debit ? $amount->cents : 0, $debit ? 0 : $amount->cents, $due);
    }
}
