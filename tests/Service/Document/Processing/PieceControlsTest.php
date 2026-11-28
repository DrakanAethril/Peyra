<?php

declare(strict_types=1);

namespace App\Tests\Service\Document\Processing;

use App\Enum\Accounting\AssistanceLevel;
use App\Service\Document\Processing\PieceControl;
use App\Service\Document\Processing\PieceControls;
use App\Service\Document\VatLine;
use App\Service\Shared\Money;
use PHPUnit\Framework\TestCase;

/**
 * The controls of a pièce's read data (step 2.2a): the VAT against its base, the totals against
 * each other, the mentions left to the student - silent at level 0, warning at A, stopping at G
 * and B unless the student posts with a reserve. Amounts worked out by hand: 2 707,00 × 20 % =
 * 541,40; 1 234,56 × 5,5 % = 67,9008, 67,90 rounded half up.
 */
final class PieceControlsTest extends TestCase
{
    public function testARightInvoicePassesAndLeavesTheMentionsToTheStudent(): void
    {
        $controls = PieceControls::check([new VatLine('20', Money::cents(270_700), Money::cents(54_140))], Money::cents(270_700), Money::cents(54_140), Money::cents(324_840), AssistanceLevel::Warnings, false);

        self::assertSame([
            ['TVA cohérente avec la base (2 707,00 × 20 %)', true, false],
            ['Total TTC cohérent (2 707,00 + 541,40 = 3 248,40)', true, false],
            ['Mentions obligatoires de la facture : à contrôler par vos soins', false, false],
        ], $this->read($controls));
    }

    public function testAVatRoundedHalfUpOnItsBase(): void
    {
        $controls = PieceControls::check([new VatLine('5.5', Money::cents(123_456), Money::cents(6_790))], null, null, null, AssistanceLevel::Warnings, false);

        self::assertTrue($controls[0]->passed);
    }

    public function testAMiscalculatedVatWarnsAtAAndStopsAtGAndB(): void
    {
        // The supplier printed 541,04 for 541,40 (two figures swapped, step 2.1b's fault).
        $vat = [new VatLine('20', Money::cents(270_700), Money::cents(54_104))];

        $warning = PieceControls::check($vat, null, null, null, AssistanceLevel::Warnings, false);
        self::assertSame(['La TVA lue (541,04) ne correspond pas à la base : 2 707,00 × 20 % = 541,40.', false, false], $this->read($warning)[0]);

        self::assertTrue(PieceControls::check($vat, null, null, null, AssistanceLevel::Guided, false)[0]->blocking);
        self::assertTrue(PieceControls::check($vat, null, null, null, AssistanceLevel::Blocking, false)[0]->blocking);
        self::assertFalse(PieceControls::check($vat, null, null, null, AssistanceLevel::Blocking, true)[0]->blocking, 'Comptabiliser avec réserve lève le blocage.');
        self::assertSame([], PieceControls::check($vat, null, null, null, AssistanceLevel::None, false), 'Aucun filet : seul l’équilibre est contrôlé.');
    }

    public function testWrongTotalsAreNamed(): void
    {
        // 2 707,00 + 541,40 = 3 248,40, printed 3 284,40; the breakdown's base 2 707,00 against a total HT of 2 770,00.
        $controls = $this->read(PieceControls::check([new VatLine('20', Money::cents(270_700), Money::cents(54_140))], Money::cents(277_000), Money::cents(54_140), Money::cents(328_440), AssistanceLevel::Warnings, false));

        self::assertContains(['Les totaux lus (HT 2 770,00, TVA 541,40) ne sont pas la somme de la ventilation par taux (HT 2 707,00, TVA 541,40).', false, false], $controls);
        self::assertContains(['Le total TTC lu (3 284,40) n’est pas la somme du HT et de la TVA (3 311,40).', false, false], $controls);
    }

    /**
     * @param list<PieceControl> $controls
     *
     * @return list<array{string, bool, bool}>
     */
    private function read(array $controls): array
    {
        return array_map(static fn (PieceControl $control): array => [str_replace(["\u{202F}", "\u{00A0}"], ' ', $control->message), $control->passed, $control->blocking], $controls);
    }
}
