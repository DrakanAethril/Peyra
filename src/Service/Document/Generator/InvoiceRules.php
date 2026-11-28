<?php

declare(strict_types=1);

namespace App\Service\Document\Generator;

use App\Enum\Document\InvoiceFault;
use App\Enum\Document\InvoiceMention;
use App\Service\Accounting\Template\VatRate;
use App\Service\Shared\DateValue;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

/**
 * The one reading of an invoice to generate (step 2.1b, pure): a supplier of the dossier, a
 * number, a date, a due date that does not precede it, at least one line - each with its
 * designation, a positive quantity of three decimals at most, a price and a VAT rate - and a total
 * excluding tax above zero. The mention left out must be one of InvoiceMention; an unknown fault
 * is dropped (only a forged request sends one).
 */
final class InvoiceRules
{
    private const int TEXT = 40;
    private const int DESIGNATION = 120;

    /**
     * The operation, or null with the order's errors set by field.
     *
     * @param callable(string): bool $isSupplier whether this code is a supplier of the dossier
     */
    public static function check(InvoiceOrder $order, callable $isSupplier): ?InvoiceOperation
    {
        $errors = [];

        if ('' === $order->supplier) {
            $errors['supplier'] = 'Choisissez le fournisseur.';
        } elseif (!$isSupplier($order->supplier)) {
            $errors['supplier'] = 'Ce fournisseur n’est pas un tiers du dossier.';
        }

        if ('' === $order->number) {
            $errors['number'] = 'Indiquez le numéro de la facture.';
        } elseif (mb_strlen($order->number) > self::TEXT) {
            $errors['number'] = \sprintf('Le numéro tient en %d caractères au plus.', self::TEXT);
        }
        if (mb_strlen($order->orderReference) > self::TEXT) {
            $errors['orderReference'] = \sprintf('La référence de commande tient en %d caractères au plus.', self::TEXT);
        }

        $date = '' === $order->date ? null : DateValue::parse($order->date);
        if (null === $date) {
            $errors['date'] = '' === $order->date ? 'Indiquez la date de la facture.' : 'Une date au format JJ/MM/AAAA.';
        }
        $dueDate = null;
        if ('' !== $order->dueDate) {
            $dueDate = DateValue::parse($order->dueDate);
            if (null === $dueDate) {
                $errors['dueDate'] = 'Une date au format JJ/MM/AAAA.';
            } elseif (null !== $date && $dueDate < $date) {
                $errors['dueDate'] = 'L’échéance ne peut pas précéder la date de la facture.';
            }
        }

        $lines = [];
        foreach ($order->lines as $i => $row) {
            if ('' === $row['designation'] && '' === $row['quantity'] && null === $row['price'] && '' === $row['rate']) {
                continue;
            }
            $line = self::line($row, 'line.'.$i.'.', $errors);
            if (null !== $line) {
                $lines[] = $line;
            }
        }
        $complete = [] === array_filter(array_keys($errors), static fn (string $key): bool => str_starts_with($key, 'line.'));
        if ($complete && [] === $lines) {
            $errors['lines'] = 'Décrivez au moins une ligne de la facture.';
        } elseif ($complete && !Money::sum(array_map(static fn (InvoiceLine $line): Money => $line->amount(), $lines))->isPositive()) {
            $errors['lines'] = 'Le total hors taxe de la facture est positif : une remise ne dépasse pas les lignes.';
        }

        $mention = null;
        if ('' !== $order->missingMention) {
            $mention = InvoiceMention::tryFrom($order->missingMention);
            if (null === $mention) {
                $errors['missingMention'] = 'Choisissez une mention de la liste.';
            }
        }
        $faults = [];
        foreach (InvoiceFault::cases() as $fault) {
            if (\in_array($fault->value, $order->faults, true)) {
                $faults[] = $fault;
            }
        }

        $order->errors = $errors;
        if ([] !== $errors || null === $date) {
            return null;
        }
        $orderReference = '' === $order->orderReference ? null : $order->orderReference;

        return new InvoiceOperation($order->supplier, $order->number, $date, $dueDate, $orderReference, $lines, $mention, $faults);
    }

    /**
     * @param array{designation: string, quantity: string, price: ?int, rate: string} $row
     * @param array<string, string>                                                   $errors
     */
    private static function line(array $row, string $key, array &$errors): ?InvoiceLine
    {
        $before = \count($errors);
        if ('' === $row['designation']) {
            $errors[$key.'designation'] = 'Indiquez ce qui est facturé.';
        } elseif (mb_strlen($row['designation']) > self::DESIGNATION) {
            $errors[$key.'designation'] = \sprintf('La désignation tient en %d caractères au plus.', self::DESIGNATION);
        }
        $quantity = self::quantity($row['quantity']);
        if (null === $quantity) {
            $errors[$key.'quantity'] = '' === $row['quantity'] ? 'Indiquez la quantité.' : 'Une quantité positive, trois décimales au plus (2,40).';
        }
        if (null === $row['price']) {
            $errors[$key.'price'] = 'Indiquez le prix unitaire hors taxe.';
        }
        $rate = '' === $row['rate'] ? null : VatRate::parse($row['rate']);
        if (null === $rate) {
            $errors[$key.'rate'] = '' === $row['rate'] ? 'Indiquez le taux de TVA.' : 'Un taux de 0 à 100 %, trois décimales au plus (20 ou 5,5).';
        }
        if (\count($errors) > $before || null === $quantity || null === $row['price'] || null === $rate) {
            return null;
        }

        return new InvoiceLine($row['designation'], $quantity, Money::cents($row['price']), $rate);
    }

    private static function quantity(string $typed): ?BigDecimal
    {
        $text = str_replace([',', ' ', "\u{202F}", "\u{00A0}"], ['.', '', '', ''], $typed);
        if (1 !== preg_match('/^\d{1,7}(\.\d{1,3})?$/', $text)) {
            return null;
        }
        try {
            $quantity = BigDecimal::of($text);
        } catch (MathException) {
            return null;
        }

        return $quantity->isPositive() ? $quantity : null;
    }
}
