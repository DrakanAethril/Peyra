<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Entity\Inventory\Article;
use App\Enum\Inventory\StockValuationMethod;
use App\Service\Shared\FrenchFormat;

/** An article as a person typed it, before ArticleRules checks it, with the error of each field. */
final class ArticleDraft
{
    public string $code = '';
    public string $designation = '';
    public string $unit = '';
    public string $salePrice = '';
    public string $purchasePrice = '';
    public string $vatRate = '';
    public string $revenueAccount = '';
    public bool $stockTracked = false;
    /** A StockValuationMethod's value. */
    public string $valuationMethod = StockValuationMethod::WeightedAverage->value;
    public string $minimumStock = '';

    /** @var array<string, string> by field */
    public array $errors = [];

    public static function of(Article $article): self
    {
        $draft = new self();
        $draft->code = $article->getCode();
        $draft->designation = $article->getDesignation();
        $draft->unit = $article->getUnit();
        $draft->salePrice = null === $article->getSalePrice() ? '' : FrenchFormat::amount($article->getSalePrice());
        $draft->purchasePrice = null === $article->getPurchasePrice() ? '' : FrenchFormat::amount($article->getPurchasePrice());
        $draft->vatRate = str_replace('.', ',', $article->getVatRate() ?? '');
        $draft->revenueAccount = $article->getRevenueAccount() ?? '';
        $draft->stockTracked = $article->isStockTracked();
        $draft->valuationMethod = $article->getValuationMethod()->value;
        $minimum = $article->getMinimumStock();
        $draft->minimumStock = null === $minimum ? '' : str_replace('.', ',', (string) $minimum->strippedOfTrailingZeros());

        return $draft;
    }

    public function error(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }
}
