<?php

declare(strict_types=1);

namespace App\Service\Purchasing;

use App\Enum\Purchasing\MatchStatus;
use App\Service\Sales\SalesComputation;
use App\Service\Sales\SalesLine;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;

/**
 * The three-way match of a supplier's invoice (step 2.4, pure; maquette 1, the card « Contrôles
 * avant validation »): the order its read order reference names, the receipts of that order, the
 * invoice. What was received is valued at the order's prices (a receipt's lines carry them); what
 * other pièces of the same order already invoiced is taken off (a credit note gives back); the
 * invoice must bring the rest, to the cent. Quantities received short of or beyond the order are
 * said apart. A credit note is matched to its order and supplier only. Read, never stored.
 */
final class InvoiceMatch
{
    /**
     * @param ?string                                       $orderNumber     the validated order the reference names; null when none does
     * @param list<SalesLine>                               $order           its lines
     * @param array<string, list<SalesLine>>                $receipts        its validated receipts' lines, by number
     * @param list<array{number: string, excludingTax: Money}> $others       the other pièces matched to it, a credit note negative
     * @param bool                                          $sameSupplier    the pièce's supplier is the order's (true when the pièce names none)
     */
    public static function of(string $reference, ?string $orderNumber, array $order, array $receipts, array $others, ?Money $invoiced, bool $creditNote, bool $sameSupplier, string $orderSupplier = ''): InvoiceMatching
    {
        if (null === $orderNumber) {
            return new InvoiceMatching($reference, MatchStatus::UnknownOrder, [], Money::zero(), Money::zero(), null, [], \sprintf('La référence de commande %s ne correspond à aucune commande validée du dossier.', $reference));
        }
        if (!$sameSupplier) {
            return new InvoiceMatching($orderNumber, MatchStatus::Gap, [], Money::zero(), Money::zero(), null, [], \sprintf('La commande %s est passée à %s : la pièce vient d’un autre fournisseur.', $orderNumber, $orderSupplier));
        }
        $progress = ReceiptProgress::of($order, array_values($receipts));
        $received = Money::sum(array_map(static fn (array $lines): Money => SalesComputation::of($lines)->excludingTax, array_values($receipts)));
        $before = Money::sum(array_map(static fn (array $other): Money => $other['excludingTax'], $others));
        $quantities = [];
        foreach ($progress as $row) {
            if ($row->remaining->isPositive() && !$row->received->isZero()) {
                $quantities[] = \sprintf('« %s » : %s reçu sur %s commandé.', $row->line->designation, self::quantity($row->received), self::quantity($row->line->quantity));
            } elseif ($row->remaining->isPositive()) {
                $quantities[] = \sprintf('« %s » : rien reçu sur %s commandé.', $row->line->designation, self::quantity($row->line->quantity));
            } elseif ($row->excess->isPositive()) {
                $quantities[] = \sprintf('« %s » : %s reçu pour %s commandé.', $row->line->designation, self::quantity($row->received), self::quantity($row->line->quantity));
            }
        }
        if ([] === $receipts) {
            return new InvoiceMatching($orderNumber, MatchStatus::NotReceived, $progress, $received, $before, null, [], \sprintf('La commande %s n’a aucune réception validée : la marchandise n’est pas reçue.', $orderNumber));
        }
        $expected = $received->minus($before);
        if ($creditNote || null === $invoiced) {
            return new InvoiceMatching($orderNumber, MatchStatus::Matched, $progress, $received, $before, null, $quantities, null, self::passed($orderNumber, $receipts, $progress));
        }
        $gap = $invoiced->minus($expected);
        if (!$gap->isZero()) {
            return new InvoiceMatching($orderNumber, MatchStatus::Gap, $progress, $received, $before, $gap, $quantities, \sprintf(
                'Écart de %s HT entre la facture (%s) et la réception non encore facturée aux prix de la commande %s (%s) : prix ou quantité facturés à vérifier.',
                FrenchFormat::amount($gap->isNegative() ? $gap->negated() : $gap),
                FrenchFormat::amount($invoiced),
                $orderNumber,
                FrenchFormat::amount($expected),
            ));
        }

        return new InvoiceMatching($orderNumber, MatchStatus::Matched, $progress, $received, $before, $gap, $quantities, null, self::passed($orderNumber, $receipts, $progress));
    }

    /**
     * « Rapprochée de la commande CF-2026-0098 et de la réception BR-2026-0112 (2,40 m³) ».
     *
     * @param array<string, list<SalesLine>> $receipts
     * @param list<ReceiptLine>              $progress
     */
    private static function passed(string $orderNumber, array $receipts, array $progress): string
    {
        $numbers = array_map(strval(...), array_keys($receipts));
        $received = [];
        foreach ($progress as $row) {
            if (!$row->received->isZero()) {
                $received[] = trim(FrenchFormat::quantity($row->received, $row->received->strippedOfTrailingZeros()->getScale() > 2 ? 3 : 2).' '.$row->line->unit);
            }
        }

        return \sprintf(
            'Rapprochée de la commande %s et %s %s (%s)',
            $orderNumber,
            \count($numbers) > 1 ? 'des réceptions' : 'de la réception',
            implode(', ', $numbers),
            implode(' · ', $received),
        );
    }

    private static function quantity(BigDecimal $quantity): string
    {
        return str_replace('.', ',', (string) $quantity->strippedOfTrailingZeros());
    }
}
