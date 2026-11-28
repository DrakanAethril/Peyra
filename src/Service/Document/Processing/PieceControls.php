<?php

declare(strict_types=1);

namespace App\Service\Document\Processing;

use App\Enum\Accounting\AssistanceLevel;
use App\Service\Accounting\Template\VatRate;
use App\Service\Document\VatLine;
use App\Service\Shared\Assistance\Assistance;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;
use Brick\Math\RoundingMode;

/**
 * The controls of a pièce's read data (design-ux.md §§ 3.2 and 4.3, the card « Contrôles avant
 * validation »): the VAT of each rate against its base (rounded half up on the base, the French
 * practice), the totals against each other, and the mandatory mentions, which the tool leaves to
 * the student. They read the data alone - never a fault the teacher slipped in (design-ux.md
 * § 3.5) - and say a rule, never the expected entry. At level 0 none runs (the balance alone is
 * checked); at A they warn; at G and B a failed one stops the posting, unless an anomaly was
 * reported with « Comptabiliser avec réserve ». A pure function.
 */
final class PieceControls
{
    /**
     * @param list<VatLine> $vatLines
     *
     * @return list<PieceControl>
     */
    public static function check(array $vatLines, ?Money $totalExcludingTax, ?Money $totalVat, ?Money $totalIncludingTax, AssistanceLevel $level, bool $reserved): array
    {
        $assistance = Assistance::at($level);
        if (!$assistance->runsControls()) {
            return [];
        }
        $blocking = $assistance->blocks() && !$reserved;
        $controls = [];

        foreach ($vatLines as $line) {
            $rate = VatRate::parse($line->rate);
            if (null === $rate) {
                continue;
            }
            $expected = $line->base->times($rate->dividedBy(100, 5), RoundingMode::HalfUp);
            $shown = FrenchFormat::amount($line->base).' × '.FrenchFormat::percent($rate);
            $controls[] = $expected->equals($line->vat)
                ? new PieceControl(\sprintf('TVA cohérente avec la base (%s)', $shown), true)
                : new PieceControl(\sprintf('La TVA lue (%s) ne correspond pas à la base : %s = %s.', FrenchFormat::amount($line->vat), $shown, FrenchFormat::amount($expected)), false, $blocking);
        }

        if ([] !== $vatLines && null !== $totalExcludingTax && null !== $totalVat) {
            $bases = Money::sum(array_map(static fn (VatLine $line): Money => $line->base, $vatLines));
            $vats = Money::sum(array_map(static fn (VatLine $line): Money => $line->vat, $vatLines));
            if (!$bases->equals($totalExcludingTax) || !$vats->equals($totalVat)) {
                $controls[] = new PieceControl(\sprintf('Les totaux lus (HT %s, TVA %s) ne sont pas la somme de la ventilation par taux (HT %s, TVA %s).', FrenchFormat::amount($totalExcludingTax), FrenchFormat::amount($totalVat), FrenchFormat::amount($bases), FrenchFormat::amount($vats)), false, $blocking);
            }
        }

        if (null !== $totalExcludingTax && null !== $totalVat && null !== $totalIncludingTax) {
            $sum = $totalExcludingTax->plus($totalVat);
            $controls[] = $sum->equals($totalIncludingTax)
                ? new PieceControl(\sprintf('Total TTC cohérent (%s + %s = %s)', FrenchFormat::amount($totalExcludingTax), FrenchFormat::amount($totalVat), FrenchFormat::amount($totalIncludingTax)), true)
                : new PieceControl(\sprintf('Le total TTC lu (%s) n’est pas la somme du HT et de la TVA (%s).', FrenchFormat::amount($totalIncludingTax), FrenchFormat::amount($sum)), false, $blocking);
        }

        $controls[] = new PieceControl('Mentions obligatoires de la facture : à contrôler par vos soins', false);

        return $controls;
    }
}
