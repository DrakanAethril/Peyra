<?php

declare(strict_types=1);

namespace App\Service\Inventory\Stock;

use App\Enum\Inventory\StockMovementKind;
use App\Service\Shared\AmountParseError;
use App\Service\Shared\AmountParser;
use App\Service\Shared\DateValue;
use App\Service\Shared\DecimalParseError;
use App\Service\Shared\DecimalParser;
use App\Service\Shared\Money;
use Brick\Math\RoundingMode;

/**
 * The one reading of a stock movement typed by hand (step 2.5, pure): a kind; an article whose
 * stock is followed; a day no later than the dossier's; a quantity of three decimals at most,
 * above zero - an adjustment's signed and never zero -; an entry's unit cost (zero or more), its
 * value being the quantity times it rounded half up to the cent; a storage place, and a transfer's
 * other one; a label, the kind's name when left empty. Whether the stock holds what goes out is
 * StockBalances', asked by StockMovements against the recorded movements.
 */
final class StockMovementRules
{
    /** @param callable(string): ?bool $tracked whether the article of that code follows its stock, null when there is no such article */
    public static function check(StockMovementDraft $draft, callable $tracked, \DateTimeImmutable $dossierDate): ?StockMovementFields
    {
        $errors = [];
        $kind = StockMovementKind::tryFrom($draft->kind);
        if (null === $kind) {
            $errors['kind'] = 'Choisissez la nature du mouvement.';
        }
        $article = strtoupper(trim($draft->article));
        if ('' === $article) {
            $errors['article'] = 'Un article est attendu.';
        } else {
            $follows = $tracked($article);
            if (null === $follows) {
                $errors['article'] = \sprintf('Le dossier n’a pas d’article %s.', $article);
            } elseif (!$follows) {
                $errors['article'] = \sprintf('Le stock de l’article %s n’est pas suivi : cochez « Suivi en stock » sur sa fiche.', $article);
            }
        }
        $date = DateValue::parse(trim($draft->date));
        if (null === $date) {
            $errors['date'] = 'Une date est attendue : JJ/MM/AAAA.';
        } elseif ($date > $dossierDate) {
            $errors['date'] = \sprintf('Un mouvement est daté au plus tard du %s, la date du dossier.', $dossierDate->format('d/m/Y'));
        }

        $quantity = null;
        try {
            $quantity = DecimalParser::parse($draft->quantity);
            $adjustment = StockMovementKind::Adjustment === $kind;
            if ($quantity->strippedOfTrailingZeros()->getScale() > 3 || $quantity->abs()->isGreaterThan(999999)
                || ($adjustment ? $quantity->isZero() : !$quantity->isPositive())) {
                $errors['quantity'] = $adjustment ? 'Une quantité en plus ou en moins (−2), jamais nulle, trois décimales au plus.' : 'Une quantité au-dessus de zéro, trois décimales au plus.';
            }
        } catch (DecimalParseError $error) {
            $errors['quantity'] = $error->getMessage();
        }

        $value = null;
        if (StockMovementKind::Entry === $kind) {
            try {
                $cost = AmountParser::parse($draft->unitCost);
                if ($cost->isNegative()) {
                    $errors['unitCost'] = 'Un coût unitaire positif ou nul.';
                } elseif (null !== $quantity) {
                    $value = $cost->times($quantity, RoundingMode::HalfUp);
                }
            } catch (AmountParseError $error) {
                $errors['unitCost'] = '' === trim($draft->unitCost) ? 'Une entrée a son coût unitaire hors taxes.' : $error->getMessage();
            }
        }

        $location = self::place($draft->location, 'location', $errors);
        $toLocation = null;
        if (StockMovementKind::Transfer === $kind) {
            $toLocation = self::place($draft->toLocation, 'toLocation', $errors);
            if (null !== $location && null !== $toLocation && mb_strtolower($location) === mb_strtolower($toLocation)) {
                $errors['toLocation'] = 'Un transfert va d’un dépôt à un autre.';
            }
        }
        $label = trim($draft->label);
        if (mb_strlen($label) > 160) {
            $errors['label'] = '160 caractères au plus.';
        }

        $draft->errors = $errors;
        if ([] !== $errors || null === $kind || null === $date || null === $quantity || null === $location) {
            return null;
        }

        return new StockMovementFields($kind, $article, $date, $quantity, StockMovementKind::Entry === $kind ? ($value ?? Money::zero()) : null, $location, $toLocation, '' === $label ? $kind->label() : $label);
    }

    /** @param array<string, string> $errors */
    private static function place(string $text, string $field, array &$errors): ?string
    {
        $place = trim($text);
        if ('' === $place) {
            $errors[$field] = 'Un dépôt est attendu : Dépôt principal, Atelier…';

            return null;
        }
        if (mb_strlen($place) > 40) {
            $errors[$field] = '40 caractères au plus.';

            return null;
        }

        return $place;
    }
}
