<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Service\Accounting\Template\VatRate;
use App\Service\Shared\AmountParseError;
use App\Service\Shared\AmountParser;

/**
 * The one reading of an article (step 2.3, pure): the fiche and the demonstration's file go
 * through it. A code of 1 to 12 capitals or digits, unique in the dossier; a designation and a
 * unit; a selling price (zero or more) asks a VAT rate and a revenue account - a raw material
 * has none of the three -; the account must be a class-7 account of the plan. A purchase price
 * (step 2.4, zero or more) asks a VAT rate too.
 */
final class ArticleRules
{
    /**
     * @param callable(string): bool $codeTaken    whether another article holds the code
     * @param callable(string): ?int $accountClass the class of an account of the plan, null when it has no such account
     */
    public static function check(ArticleDraft $draft, callable $codeTaken, callable $accountClass): ?ArticleFields
    {
        $errors = [];
        $code = strtoupper(trim($draft->code));
        if (1 !== preg_match('/^[A-Z0-9]{1,12}$/', $code)) {
            $errors['code'] = 'Une référence de 1 à 12 lettres capitales ou chiffres : PF001.';
        } elseif ($codeTaken($code)) {
            $errors['code'] = \sprintf('La référence %s est déjà celle d’un autre article.', $code);
        }
        $designation = trim($draft->designation);
        if ('' === $designation) {
            $errors['designation'] = 'Une désignation est attendue.';
        } elseif (mb_strlen($designation) > 160) {
            $errors['designation'] = '160 caractères au plus.';
        }
        $unit = trim($draft->unit);
        if ('' === $unit) {
            $errors['unit'] = 'Une unité est attendue : pièce, m², heure…';
        } elseif (mb_strlen($unit) > 20) {
            $errors['unit'] = '20 caractères au plus.';
        }

        $price = null;
        if ('' !== trim($draft->salePrice)) {
            try {
                $price = AmountParser::parse($draft->salePrice);
                if ($price->isNegative()) {
                    $errors['salePrice'] = 'Un prix de vente est positif ou nul.';
                }
            } catch (AmountParseError $error) {
                $errors['salePrice'] = $error->getMessage();
            }
        }
        $purchasePrice = null;
        if ('' !== trim($draft->purchasePrice)) {
            try {
                $purchasePrice = AmountParser::parse($draft->purchasePrice);
                if ($purchasePrice->isNegative()) {
                    $errors['purchasePrice'] = 'Un prix d’achat est positif ou nul.';
                }
            } catch (AmountParseError $error) {
                $errors['purchasePrice'] = $error->getMessage();
            }
        }
        $rate = null;
        if ('' !== trim($draft->vatRate)) {
            $parsed = VatRate::parse($draft->vatRate);
            if (null === $parsed) {
                $errors['vatRate'] = 'Un taux en pourcentage, de 0 à 100 : 20 ou 5,5.';
            } else {
                $rate = VatRate::canonical($parsed);
            }
        }
        $account = trim($draft->revenueAccount);
        if ('' !== $account) {
            $class = $accountClass($account);
            if (null === $class) {
                $errors['revenueAccount'] = \sprintf('Le plan du dossier n’a pas de compte %s.', $account);
            } elseif (7 !== $class) {
                $errors['revenueAccount'] = 'Un compte de produits (classe 7) : 701000, 706000…';
            }
        }
        if (null !== $price) {
            if ('' === trim($draft->vatRate) && !isset($errors['vatRate'])) {
                $errors['vatRate'] = 'Un article vendu a son taux de TVA.';
            }
            if ('' === $account && !isset($errors['revenueAccount'])) {
                $errors['revenueAccount'] = 'Un article vendu a son compte de produits.';
            }
        }

        if (null !== $purchasePrice && '' === trim($draft->vatRate) && !isset($errors['vatRate'])) {
            $errors['vatRate'] = 'Un article acheté a son taux de TVA.';
        }

        $draft->errors = $errors;
        if ([] !== $errors) {
            return null;
        }

        return new ArticleFields($code, $designation, $unit, $price, $rate, '' === $account ? null : $account, $draft->stockTracked, $purchasePrice);
    }
}
