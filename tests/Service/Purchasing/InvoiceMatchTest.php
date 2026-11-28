<?php

declare(strict_types=1);

namespace App\Tests\Service\Purchasing;

use App\Enum\Purchasing\MatchStatus;
use App\Service\Purchasing\InvoiceMatch;
use App\Service\Sales\SalesLine;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

/**
 * The three-way match (step 2.4) on the demonstration's January purchase (operations-2026.md § 2.2):
 * CF-0001 = chêne 27 mm 1,80 × 1 150,00 − 5 % = 1 966,50, chêne 54 mm 1,20 × 1 240,00 − 5 % =
 * 1 413,60, port 85,00; HT 3 465,10 - the HT of the invoice FA-2601-0012.
 */
final class InvoiceMatchTest extends TestCase
{
    public function testTheInvoiceOfTheWholeReceiptMatches(): void
    {
        $match = InvoiceMatch::of('CF-2026-0001', 'CF-2026-0001', self::order(), ['BR-2026-0001' => self::received('1.8', '1.2')], [], Money::cents(346_510), false, true);

        self::assertSame(MatchStatus::Matched, $match->status);
        self::assertSame(346_510, $match->received->cents);
        self::assertSame(0, $match->gap?->cents);
        self::assertNull($match->fault);
        self::assertSame([], $match->quantities);
        self::assertSame('Rapprochée de la commande CF-2026-0001 et de la réception BR-2026-0001 (1,80 m³ · 1,20 m³ · 1,00 forfait)', $match->matched);
    }

    public function testAPriceOrQuantityInvoicedBeyondIsAGap(): void
    {
        $match = InvoiceMatch::of('CF-2026-0001', 'CF-2026-0001', self::order(), ['BR-2026-0001' => self::received('1.8', '1.2')], [], Money::cents(355_000), false, true);

        self::assertSame(MatchStatus::Gap, $match->status);
        self::assertSame(8_490, $match->gap?->cents, '3 550,00 − 3 465,10.');
        self::assertStringStartsWith('Écart de 84,90 HT entre la facture (3', (string) $match->fault);
    }

    public function testWhatOtherPiecesInvoicedIsTakenOff(): void
    {
        // Received: 1,5 m³ of 27 mm (1 725,00 − 86,25 = 1 638,75) + 1,2 m³ of 54 mm (1 413,60) + port 85,00 = 3 137,35.
        $receipts = ['BR-2026-0001' => self::received('1.5', '1.2')];
        $others = [['number' => 'FA-1', 'excludingTax' => Money::cents(200_000)], ['number' => 'AV-1', 'excludingTax' => Money::cents(-10_000)]];
        $match = InvoiceMatch::of('CF-2026-0001', 'CF-2026-0001', self::order(), $receipts, $others, Money::cents(123_735), false, true);

        self::assertSame([313_735, 190_000], [$match->received->cents, $match->invoicedBefore->cents]);
        self::assertSame(MatchStatus::Matched, $match->status, '3 137,35 − 1 900,00 = 1 237,35.');
        self::assertSame(['« Chêne avivé 27 mm » : 1,5 reçu sur 1,8 commandé.'], $match->quantities);
    }

    public function testWhatStopsAMatch(): void
    {
        $unknown = InvoiceMatch::of('CF-0001', null, [], [], [], Money::cents(1), false, true);
        self::assertSame([MatchStatus::UnknownOrder, 'La référence de commande CF-0001 ne correspond à aucune commande validée du dossier.'], [$unknown->status, $unknown->fault]);

        $elsewhere = InvoiceMatch::of('CF-2026-0001', 'CF-2026-0001', self::order(), [], [], Money::cents(1), false, false, 'Scierie du Haut-Forez');
        self::assertSame('La commande CF-2026-0001 est passée à Scierie du Haut-Forez : la pièce vient d’un autre fournisseur.', $elsewhere->fault);

        $waiting = InvoiceMatch::of('CF-2026-0001', 'CF-2026-0001', self::order(), [], [], Money::cents(346_510), false, true);
        self::assertSame([MatchStatus::NotReceived, 'La commande CF-2026-0001 n’a aucune réception validée : la marchandise n’est pas reçue.'], [$waiting->status, $waiting->fault]);

        $credit = InvoiceMatch::of('CF-2026-0001', 'CF-2026-0001', self::order(), ['BR-2026-0001' => self::received('1.8', '1.2')], [], Money::cents(10_000), true, true);
        self::assertSame([MatchStatus::Matched, null], [$credit->status, $credit->gap], 'A credit note is matched to its order, never compared.');
    }

    /** @return list<SalesLine> */
    private static function order(): array
    {
        return [
            new SalesLine('MP001', 'Chêne avivé 27 mm', 'm³', BigDecimal::of('1.8'), Money::cents(115_000), BigDecimal::of('5'), '20'),
            new SalesLine('MP003', 'Chêne avivé 54 mm', 'm³', BigDecimal::of('1.2'), Money::cents(124_000), BigDecimal::of('5'), '20'),
            new SalesLine(null, 'Port', 'forfait', BigDecimal::one(), Money::cents(8_500), null, '20'),
        ];
    }

    /** @return list<SalesLine> a receipt of the first two lines in those quantities, and the port */
    private static function received(string $first, string $second): array
    {
        $order = self::order();

        return [
            new SalesLine('MP001', $order[0]->designation, 'm³', BigDecimal::of($first), $order[0]->unitPrice, $order[0]->discount, '20', 0),
            new SalesLine('MP003', $order[1]->designation, 'm³', BigDecimal::of($second), $order[1]->unitPrice, $order[1]->discount, '20', 1),
            new SalesLine(null, 'Port', 'forfait', BigDecimal::one(), Money::cents(8_500), null, '20', 2),
        ];
    }
}
