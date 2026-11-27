<?php

declare(strict_types=1);

namespace App\Tests\Service\Accounting\Chart;

use App\Service\Accounting\Chart\AccountSearch;
use PHPUnit\Framework\TestCase;

/** The account picker's search (design-ui.md § 4.6): digits by the start of the number, words by the label. */
final class AccountSearchTest extends TestCase
{
    /** @var list<array{number: string, label: string}> */
    private const array PLAN = [
        ['number' => '401000', 'label' => 'Fournisseurs'],
        ['number' => '445660', 'label' => 'Taxe sur la valeur ajoutée déductible sur autres biens et services'],
        ['number' => '512100', 'label' => 'Crédit Coopératif'],
        ['number' => '601000', 'label' => 'Achats stockés - Matières premières et fournitures'],
        ['number' => '601300', 'label' => 'Achats de vitrages'],
        ['number' => '624100', 'label' => 'Transports sur achats'],
    ];

    /** @return list<string> */
    private static function find(string $query, int $limit = 0): array
    {
        return array_map(static fn (array $a): string => $a['number'], AccountSearch::filter(self::PLAN, static fn (array $a): array => $a, $query, $limit));
    }

    public function testDigitsFindTheStartOfTheNumber(): void
    {
        self::assertSame(['601000', '601300'], self::find('60'));
        self::assertSame(['601300'], self::find('6013'));
        self::assertSame(['601300'], self::find('601 3'));
        self::assertSame([], self::find('609'));
    }

    public function testWordsFindTheLabelWithoutAccentsNorCase(): void
    {
        self::assertSame(['601000', '601300', '624100'], self::find('achat'));
        self::assertSame(['512100'], self::find('credit coop'));
        self::assertSame(['445660'], self::find('DÉDUCTIBLE'));
        self::assertSame(['601300'], self::find('achats vitr'));
        self::assertSame([], self::find('achats salaires'));
    }

    public function testAnEmptySearchListsThePlanUpToTheLimit(): void
    {
        self::assertCount(6, self::find(''));
        self::assertSame(['401000', '445660'], self::find('', 2));
        self::assertSame(['601000'], self::find('achat', 1));
    }

    public function testFoldDropsAccentsCaseAndPunctuation(): void
    {
        self::assertSame('operations diverses', AccountSearch::fold('Opérations  diverses'));
        self::assertSame('a nouveaux', AccountSearch::fold('À-nouveaux'));
    }
}
