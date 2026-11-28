<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Enum\Document\DocumentType;
use App\Service\Accounting\Template\VatRate;
use App\Service\Shared\DateValue;
use App\Service\Shared\Money;

/**
 * The one reading of a pièce's read data (pure): their shape, never their arithmetic. A total that
 * does not add up, a VAT miscalculated, a due date before the invoice's are what a pièce may print
 * and what its processing (step 2.2) has the student find - so they are kept as typed. What is
 * refused: an unknown nature, a third party not of the dossier, a date that is not one, a text too
 * long, a VAT row without its rate or its base, a rate that is not one, the same rate twice.
 */
final class DocumentReadRules
{
    private const int TEXT = 40;

    /**
     * The facts, or null with the reading's errors set by field.
     *
     * @param callable(int): bool $thirdPartyExists whether this id is a third party of the dossier
     */
    public static function check(DocumentReading $reading, callable $thirdPartyExists): ?DocumentFacts
    {
        $errors = [];

        $type = DocumentType::tryFrom($reading->type);
        if (null === $type) {
            $errors['type'] = 'Choisissez la nature de la pièce.';
        }

        $thirdParty = null;
        if ('' !== trim($reading->thirdParty)) {
            $id = ctype_digit(trim($reading->thirdParty)) ? (int) trim($reading->thirdParty) : 0;
            if ($id < 1 || !$thirdPartyExists($id)) {
                $errors['thirdParty'] = 'Ce tiers n’est pas un tiers du dossier.';
            } else {
                $thirdParty = $id;
            }
        }

        $number = self::text($reading->number, 'number', 'Le numéro', $errors);
        $orderReference = self::text($reading->orderReference, 'orderReference', 'La référence de commande', $errors);
        $date = self::date($reading->date, 'date', $errors);
        $dueDate = self::date($reading->dueDate, 'dueDate', $errors);

        $lines = [];
        $rates = [];
        foreach ($reading->vat as $index => $row) {
            $rateText = trim($row['rate']);
            if ('' === $rateText && null === $row['base'] && null === $row['vat']) {
                continue;
            }
            $rate = '' === $rateText ? null : VatRate::parse($rateText);
            if ('' === $rateText) {
                $errors['vat.'.$index.'.rate'] = 'Indiquez le taux de cette ligne.';
            } elseif (null === $rate) {
                $errors['vat.'.$index.'.rate'] = 'Un taux de 0 à 100 %, trois décimales au plus (20 ou 5,5).';
            } elseif (\in_array(VatRate::canonical($rate), $rates, true)) {
                $errors['vat.'.$index.'.rate'] = 'Ce taux figure déjà sur une autre ligne.';
            }
            if (null === $row['base']) {
                $errors['vat.'.$index.'.base'] = 'Indiquez la base hors taxe de ce taux.';
            }
            if (null !== $rate && null !== $row['base'] && !isset($errors['vat.'.$index.'.rate'])) {
                $rates[] = VatRate::canonical($rate);
                $lines[] = new VatLine(VatRate::canonical($rate), Money::cents($row['base']), Money::cents($row['vat'] ?? 0));
            }
        }

        $reading->errors = $errors;
        if ([] !== $errors || null === $type) {
            return null;
        }

        return new DocumentFacts(
            $type,
            $thirdParty,
            $number,
            $date,
            $dueDate,
            $orderReference,
            $lines,
            null === $reading->totalExcludingTax ? null : Money::cents($reading->totalExcludingTax),
            null === $reading->totalVat ? null : Money::cents($reading->totalVat),
            null === $reading->totalIncludingTax ? null : Money::cents($reading->totalIncludingTax),
        );
    }

    /** @param array<string, string> $errors */
    private static function text(string $typed, string $field, string $what, array &$errors): ?string
    {
        $text = trim($typed);
        if (mb_strlen($text) > self::TEXT) {
            $errors[$field] = \sprintf('%s tient en %d caractères au plus.', $what, self::TEXT);
        }

        return '' === $text ? null : $text;
    }

    /** @param array<string, string> $errors */
    private static function date(string $typed, string $field, array &$errors): ?\DateTimeImmutable
    {
        if ('' === trim($typed)) {
            return null;
        }
        $date = DateValue::parse(trim($typed));
        if (null === $date) {
            $errors[$field] = 'Une date au format JJ/MM/AAAA.';
        }

        return $date;
    }
}
