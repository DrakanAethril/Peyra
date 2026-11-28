<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Entity\Inventory\Article;
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

        return $draft;
    }

    public function error(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }
}
