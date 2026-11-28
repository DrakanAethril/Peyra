<?php

declare(strict_types=1);

namespace App\Service\Sales;

use App\Service\Accounting\Template\VatRate;
use App\Service\Shared\AmountParseError;
use App\Service\Shared\AmountParser;
use App\Service\Shared\DateValue;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

/**
 * The one reading of a commercial document (step 2.3, pure), at each F10 of its fiche and for the
 * demonstration's file: a customer of the dossier, a date, a due date not before it, a reference
 * of 80 characters at most, and lines - a row left entirely empty is dropped; every other one has
 * its designation, a quantity above zero (three decimals at most), a price excluding tax (zero or
 * more), a discount from 0 to 100 % (two decimals at most) and a VAT rate; an article named must be
 * one of the dossier. Faults under `customer`, `date`, `dueDate`, `reference`, `line.<i>.<field>`.
 *
 * What a validation adds (a line at least, the dates against the dossier and the numbering) is
 * `validationRefusals()`.
 */
final class SalesDocumentRules
{
    /**
     * @param callable(string): ?bool $customerKind true for a customer of the dossier, false for another third party, null for no third party
     * @param callable(string): bool  $articleExists
     */
    public static function check(SalesDocumentDraft $draft, callable $customerKind, callable $articleExists): ?SalesDocumentFields
    {
        $errors = [];
        $customer = strtoupper(trim($draft->customer));
        if ('' === $customer) {
            $errors['customer'] = 'Choisissez le client.';
        } else {
            $isCustomer = $customerKind($customer);
            if (null === $isCustomer) {
                $errors['customer'] = \sprintf('Le dossier n’a pas de tiers %s.', $customer);
            } elseif (!$isCustomer) {
                $errors['customer'] = \sprintf('Le tiers %s n’est pas un client.', $customer);
            }
        }
        $date = DateValue::parse($draft->date);
        if (null === $date) {
            $errors['date'] = 'Une date est attendue : JJ/MM/AAAA.';
        }
        $dueDate = null;
        if ('' !== trim($draft->dueDate)) {
            $dueDate = DateValue::parse($draft->dueDate);
            if (null === $dueDate) {
                $errors['dueDate'] = 'Une date est attendue : JJ/MM/AAAA.';
            } elseif (null !== $date && $dueDate < $date) {
                $errors['dueDate'] = 'L’échéance ne précède pas la date du document.';
            }
        }
        $reference = trim($draft->reference);
        if (mb_strlen($reference) > 80) {
            $errors['reference'] = '80 caractères au plus.';
        }

        $lines = [];
        $kept = [];
        foreach ($draft->lines as $row) {
            if ('' === implode('', array_map('trim', $row))) {
                continue;
            }
            $kept[] = $row;
        }
        $draft->lines = $kept;
        foreach ($kept as $i => $row) {
            $line = self::line($row, $i, $errors, $articleExists);
            if (null !== $line) {
                $lines[] = $line;
            }
        }

        $draft->errors = $errors;
        if ([] !== $errors || null === $date) {
            return null;
        }

        return new SalesDocumentFields($customer, $date, $dueDate, '' === $reference ? null : $reference, $lines);
    }

    /**
     * Why a document may not be validated now, in the order to fix them; empty when it may.
     *
     * @param int                     $lineCount   its lines
     * @param \DateTimeImmutable|null $lastNumbered the date of the last document of its type and year numbered
     *
     * @return list<string>
     */
    public static function validationRefusals(int $lineCount, \DateTimeImmutable $date, \DateTimeImmutable $dossierDate, ?\DateTimeImmutable $lastNumbered): array
    {
        $refusals = [];
        if (0 === $lineCount) {
            $refusals[] = 'Le document n’a aucune ligne.';
        }
        if ($date > $dossierDate) {
            $refusals[] = \sprintf('Le document est daté après la date du dossier (%s).', $dossierDate->format('d/m/Y'));
        }
        if (null !== $lastNumbered && $date < $lastNumbered) {
            $refusals[] = \sprintf('La numérotation suit l’ordre des dates : le dernier numéro de ce type est daté du %s.', $lastNumbered->format('d/m/Y'));
        }

        return $refusals;
    }

    /**
     * @param array{article: string, designation: string, unit: string, quantity: string, unitPrice: string, discount: string, rate: string} $row
     * @param array<string, string>                                                                                                           $errors
     * @param callable(string): bool                                                                                                          $articleExists
     */
    private static function line(array $row, int $i, array &$errors, callable $articleExists): ?SalesLine
    {
        $key = 'line.'.$i.'.';
        $before = \count($errors);
        $article = strtoupper(trim($row['article']));
        if ('' !== $article && !$articleExists($article)) {
            $errors[$key.'article'] = \sprintf('Le dossier n’a pas d’article %s.', $article);
        }
        $designation = trim($row['designation']);
        if ('' === $designation) {
            $errors[$key.'designation'] = 'Une désignation est attendue.';
        } elseif (mb_strlen($designation) > 160) {
            $errors[$key.'designation'] = '160 caractères au plus.';
        }
        $unit = trim($row['unit']);
        if (mb_strlen($unit) > 20) {
            $errors[$key.'unit'] = '20 caractères au plus.';
        }
        $quantity = self::decimal($row['quantity'], 3);
        if (null === $quantity || !$quantity->isPositive() || $quantity->isGreaterThan(999999)) {
            $errors[$key.'quantity'] = 'Une quantité au-dessus de zéro, trois décimales au plus.';
        }
        $price = null;
        try {
            $price = AmountParser::parse($row['unitPrice']);
            if ($price->isNegative()) {
                $errors[$key.'unitPrice'] = 'Un prix positif ou nul : une remise se donne en pourcentage.';
            }
        } catch (AmountParseError $error) {
            $errors[$key.'unitPrice'] = '' === trim($row['unitPrice']) ? 'Un prix unitaire est attendu.' : $error->getMessage();
        }
        $discount = null;
        if ('' !== trim($row['discount'])) {
            $discount = self::decimal($row['discount'], 2);
            if (null === $discount || $discount->isNegative() || $discount->isGreaterThan(100)) {
                $errors[$key.'discount'] = 'Une remise de 0 à 100 %, deux décimales au plus.';
            }
        }
        $rate = VatRate::parse($row['rate']);
        if (null === $rate) {
            $errors[$key.'rate'] = 'Un taux de TVA en pourcentage : 20 ou 5,5.';
        }
        if (\count($errors) > $before || null === $quantity || null === $price || null === $rate) {
            return null;
        }

        return new SalesLine('' === $article ? null : $article, $designation, $unit, $quantity, $price, null === $discount || $discount->isZero() ? null : $discount, VatRate::canonical($rate));
    }

    private static function decimal(string $typed, int $scale): ?BigDecimal
    {
        $text = str_replace([',', ' ', "\u{202F}", "\u{00A0}", '%'], ['.', '', '', '', ''], trim($typed));
        if (1 !== preg_match('/^\d{1,7}(\.\d{1,'.$scale.'})?$/', $text)) {
            return null;
        }
        try {
            return BigDecimal::of($text);
        } catch (MathException) {
            return null;
        }
    }
}
