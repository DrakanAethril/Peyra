<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Regulatory\RegulatoryDisplay;
use App\Service\Regulatory\RegulatoryLeaf;
use App\Service\Regulatory\RegulatoryTree;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/** The regulatory values in the templates (App\Service\Regulatory\RegulatoryDisplay). */
final class RegulatoryExtension
{
    /** `leaf.value|regulatory_leaf(row.unit)`: « 12,02 €/heure ». */
    #[AsTwigFilter('regulatory_leaf')]
    public static function leaf(string|bool|null $value, string $unit): string
    {
        return RegulatoryDisplay::leaf($value, $unit);
    }

    /** `row.unit|regulatory_suffix`: « % », « €/mois », or nothing. */
    #[AsTwigFilter('regulatory_suffix')]
    public static function suffix(string $unit): string
    {
        return RegulatoryDisplay::suffix($unit);
    }

    #[AsTwigFilter('regulatory_unit')]
    public static function unitName(string $unit): string
    {
        return RegulatoryDisplay::unitName($unit);
    }

    /**
     * `regulatory_table(row.tree)`: a list of brackets as columns and rows, or null.
     *
     * @param string|bool|array<array-key, mixed>|null $tree
     *
     * @return array{columns: list<string>, rows: list<list<string|bool|null>>}|null
     */
    #[AsTwigFunction('regulatory_table')]
    public static function table(string|bool|array|null $tree): ?array
    {
        return RegulatoryTree::asTable($tree);
    }

    /**
     * `regulatory_leaves(row.tree)`: every leaf of a value, depth first.
     *
     * @param string|bool|array<array-key, mixed>|null $tree
     *
     * @return list<RegulatoryLeaf>
     */
    #[AsTwigFunction('regulatory_leaves')]
    public static function leaves(string|bool|array|null $tree): array
    {
        return RegulatoryTree::leaves($tree);
    }
}
