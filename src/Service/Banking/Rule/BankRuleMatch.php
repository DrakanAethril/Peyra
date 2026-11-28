<?php

declare(strict_types=1);

namespace App\Service\Banking\Rule;

use App\Entity\Banking\BankRule;
use App\Service\Accounting\Chart\AccountSearch;

/**
 * Which assignment rule a statement line falls under (design-ux.md § 3.2: « si le libellé
 * contient… »): the first, in the rules' order, whose text the line's wording contains - case and
 * accents aside. Pure.
 */
final class BankRuleMatch
{
    /** @param list<BankRule> $rules */
    public static function first(array $rules, string $wording): ?BankRule
    {
        $folded = AccountSearch::fold($wording);
        foreach ($rules as $rule) {
            $pattern = AccountSearch::fold(trim($rule->getPattern()));
            if ('' !== $pattern && str_contains($folded, $pattern)) {
                return $rule;
            }
        }

        return null;
    }
}
