<?php

declare(strict_types=1);

namespace App\Tests\Service\Inventory;

use App\Service\Inventory\ArticleDraft;
use App\Service\Inventory\ArticleRules;
use PHPUnit\Framework\TestCase;

/** The reading of an article (step 2.3). */
final class ArticleRulesTest extends TestCase
{
    public function testASoldArticle(): void
    {
        $draft = self::draft('pf001', '1 380,00', '20', '701000');
        $fields = self::check($draft);

        self::assertNotNull($fields, implode(' ', $draft->errors));
        self::assertSame(['PF001', 138_000, '20', '701000'], [$fields->code, $fields->salePrice?->cents, $fields->vatRate, $fields->revenueAccount]);
    }

    public function testARawMaterialHasNoPrice(): void
    {
        $fields = self::check(self::draft('MP001', '', '', ''));

        self::assertNotNull($fields);
        self::assertNull($fields->salePrice);
        self::assertNull($fields->vatRate);
    }

    public function testASoldArticleAsksItsRateAndItsAccount(): void
    {
        $draft = self::draft('PF001', '100', '', '');
        self::assertNull(self::check($draft));
        self::assertSame(['vatRate' => 'Un article vendu a son taux de TVA.', 'revenueAccount' => 'Un article vendu a son compte de produits.'], $draft->errors);
    }

    public function testTheAccountIsARevenueOfThePlan(): void
    {
        $draft = self::draft('PF001', '100', '20', '601000');
        self::assertNull(self::check($draft));
        self::assertSame('Un compte de produits (classe 7) : 701000, 706000…', $draft->error('revenueAccount'));

        $draft = self::draft('PF001', '100', '20', '709999');
        self::assertNull(self::check($draft));
        self::assertSame('Le plan du dossier n’a pas de compte 709999.', $draft->error('revenueAccount'));
    }

    public function testTheCodeIsUnique(): void
    {
        $draft = self::draft('TAKEN', '', '', '');
        self::assertNull(self::check($draft));
        self::assertSame('La référence TAKEN est déjà celle d’un autre article.', $draft->error('code'));

        $draft = self::draft('PF-001', '', '', '');
        self::assertNull(self::check($draft));
        self::assertArrayHasKey('code', $draft->errors);
    }

    private static function draft(string $code, string $price, string $rate, string $account): ArticleDraft
    {
        $draft = new ArticleDraft();
        [$draft->code, $draft->designation, $draft->unit, $draft->salePrice, $draft->vatRate, $draft->revenueAccount] = [$code, 'Article', 'pièce', $price, $rate, $account];

        return $draft;
    }

    private static function check(ArticleDraft $draft): ?\App\Service\Inventory\ArticleFields
    {
        return ArticleRules::check(
            $draft,
            static fn (string $code): bool => 'TAKEN' === $code,
            static fn (string $number): ?int => ['701000' => 7, '601000' => 6][$number] ?? null,
        );
    }
}
