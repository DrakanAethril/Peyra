<?php

declare(strict_types=1);

namespace App\Tests\Service\Dossier\Search;

use App\Service\Dossier\Search\SearchRanking;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SearchRankingTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, ?string, ?int}> */
    public static function cases(): iterable
    {
        yield 'a journal’s code typed whole' => ['ach', 'Achats', 'saisie journal', 'ACH', SearchRanking::CODE];
        yield 'a code typed whole, any case, spaces dropped' => ['B Q2', 'Banque Populaire', '', 'BQ2', SearchRanking::CODE];
        yield 'the start of a code' => ['bq', 'Banque Populaire', '', 'BQ2', SearchRanking::CODE_START];
        yield 'the label’s first word' => ['bal', 'Balance âgée', 'echeances', null, SearchRanking::FIRST_WORD];
        yield 'without accents' => ['agee', 'Balance âgée', '', null, SearchRanking::WORD_STARTS];
        yield 'every word starts a word' => ['bal ag', 'Balance âgée', '', null, SearchRanking::FIRST_WORD];
        yield 'a word of the terms' => ['loyer', 'Abonnements', 'loyer echeances generer', null, SearchRanking::WORD_STARTS];
        yield 'a word merely held' => ['ach', 'Dettes rattachées à des participations', '', '171000', SearchRanking::HELD];
        yield 'one word that answers nothing' => ['ach', 'Balance', 'edition', null, null];
        yield 'one word of two that answers nothing' => ['balance zzz', 'Balance âgée', '', null, null];
        yield 'nothing typed' => ['  ', 'Balance', '', null, null];
    }

    #[DataProvider('cases')]
    public function testRank(string $query, string $label, string $terms, ?string $code, ?int $expected): void
    {
        self::assertSame($expected, SearchRanking::rank($query, $label, $terms, $code));
    }

    public function testTheOrderPutsTheCodeBeforeTheWords(): void
    {
        // « ach »: the purchases' journal before a label that starts with the word, before one that holds it.
        self::assertLessThan(SearchRanking::rank('ach', 'Achats stockés', '', '601000'), SearchRanking::rank('ach', 'Achats', '', 'ACH'));
        self::assertLessThan(SearchRanking::rank('ach', 'Dettes rattachées', '', '171000'), SearchRanking::rank('ach', 'Achats stockés', '', '601000'));
    }
}
