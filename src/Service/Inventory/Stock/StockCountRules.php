<?php

declare(strict_types=1);

namespace App\Service\Inventory\Stock;

use App\Service\Shared\DateValue;
use App\Service\Shared\DecimalParseError;
use App\Service\Shared\DecimalParser;
use App\Service\Shared\FrenchFormat;
use Brick\Math\BigDecimal;

/**
 * The one reading of a physical inventory (step 2.5b, pure): what opens one - a day no later than
 * the dossier's, a storage place -; what a count sheet typed - a quantity counted of three decimals
 * at most, zero or more, empty while the article is not counted, and the reason given for a gap -;
 * and what stops its validation: nothing counted, a counted article whose stock is no longer
 * followed, and, where the level asks it (G and B: « écart à justifier avant validation »), a gap
 * without its reason. An article left uncounted keeps its stock: no adjustment is made for it.
 */
final class StockCountRules
{
    /**
     * @param array<string, string> $errors filled under `date` and `location`
     *
     * @return array{0: \DateTimeImmutable, 1: string}|null the day and the place, or null when refused
     */
    public static function opening(string $date, string $location, \DateTimeImmutable $dossierDate, array &$errors): ?array
    {
        $errors = [];
        $day = DateValue::parse(trim($date));
        if (null === $day) {
            $errors['date'] = 'Une date est attendue : JJ/MM/AAAA.';
        } elseif ($day > $dossierDate) {
            $errors['date'] = \sprintf('Un inventaire est daté au plus tard du %s, la date du dossier.', $dossierDate->format('d/m/Y'));
        }
        $place = trim($location);
        if ('' === $place) {
            $errors['location'] = 'Un dépôt est attendu : Dépôt principal, Atelier…';
        } elseif (mb_strlen($place) > 40) {
            $errors['location'] = '40 caractères au plus.';
        }

        return [] === $errors && null !== $day ? [$day, $place] : null;
    }

    /**
     * The lines as the sheet typed them, line by line in the inventory's order.
     *
     * @param list<StockCountLine>                               $lines
     * @param array<int, array{counted: string, reason: string}> $typed by line index; a line not sent keeps what it holds
     * @param array<string, string>                              $errors filled under `line.<i>.counted` and `line.<i>.reason`
     *
     * @return list<StockCountLine>|null null when a field is refused
     */
    public static function counts(array $lines, array $typed, array &$errors): ?array
    {
        $errors = [];
        $read = [];
        foreach ($lines as $index => $line) {
            if (!isset($typed[$index])) {
                $read[] = $line;
                continue;
            }
            $counted = null;
            $text = trim($typed[$index]['counted']);
            if ('' !== $text) {
                try {
                    $counted = DecimalParser::parse($text);
                    if ($counted->isNegative() || $counted->strippedOfTrailingZeros()->getScale() > 3 || $counted->isGreaterThan(999999)) {
                        $errors['line.'.$index.'.counted'] = 'Une quantité de zéro ou plus, trois décimales au plus.';
                    }
                } catch (DecimalParseError $error) {
                    $errors['line.'.$index.'.counted'] = $error->getMessage();
                }
            }
            $reason = trim($typed[$index]['reason']);
            if (mb_strlen($reason) > 160) {
                $errors['line.'.$index.'.reason'] = '160 caractères au plus.';
            }
            $read[] = $line->withCount($counted, $reason);
        }

        return [] === $errors ? $read : null;
    }

    /**
     * The lines whose gap has no reason: a counted quantity other than the theoretical one.
     *
     * @param list<StockCountLine> $lines
     * @param list<BigDecimal>     $theoretical by line index
     *
     * @return list<int> their indexes
     */
    public static function unjustified(array $lines, array $theoretical): array
    {
        $indexes = [];
        foreach ($lines as $index => $line) {
            $gap = $line->gapFrom($theoretical[$index] ?? BigDecimal::zero());
            if (null !== $gap && !$gap->isZero() && '' === $line->reason) {
                $indexes[] = $index;
            }
        }

        return $indexes;
    }

    /**
     * Why the inventory may not be validated, in the order the sheet reads.
     *
     * @param list<StockCountLine>   $lines
     * @param list<BigDecimal>       $theoretical by line index
     * @param callable(string): bool $followed    whether the article of that code still follows its stock
     *
     * @return list<string>
     */
    public static function refusals(array $lines, array $theoretical, callable $followed, bool $reasonRequired): array
    {
        $refusals = [];
        $counted = array_filter($lines, static fn (StockCountLine $line): bool => null !== $line->counted);
        if ([] === $counted) {
            $refusals[] = 'Aucun article n’est compté : saisissez au moins une quantité comptée.';
        }
        foreach ($counted as $line) {
            if (!$followed($line->article)) {
                $refusals[] = \sprintf('L’article %s n’est plus suivi en stock : laissez sa quantité vide.', $line->article);
            }
        }
        if ($reasonRequired) {
            foreach (self::unjustified($lines, $theoretical) as $index) {
                $line = $lines[$index];
                $gap = $line->gapFrom($theoretical[$index] ?? BigDecimal::zero()) ?? BigDecimal::zero();
                $refusals[] = \sprintf('%s : l’écart de %s%s est à justifier.', $line->article, $gap->isPositive() ? '+' : '−', FrenchFormat::number($gap->abs()));
            }
        }

        return $refusals;
    }
}
