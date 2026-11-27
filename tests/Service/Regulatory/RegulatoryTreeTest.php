<?php

declare(strict_types=1);

namespace App\Tests\Service\Regulatory;

use App\Service\Regulatory\RegulatoryTree;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class RegulatoryTreeTest extends TestCase
{
    public function testNumbersBecomeTheirExactDecimalTextNeverAFloat(): void
    {
        $yaml = Yaml::parse("a: 6.90\nb: 0.3193\nc: 48060\nd: 0.00001\ne: 12.02\nf: -2.5\ng: 1.0", Yaml::PARSE_DATETIME);

        self::assertSame(
            ['a' => '6.9', 'b' => '0.3193', 'c' => '48060', 'd' => '0.00001', 'e' => '12.02', 'f' => '-2.5', 'g' => '1'],
            RegulatoryTree::fromYaml($yaml),
        );
    }

    public function testDatesTextsBooleansAndStructuresAreKept(): void
    {
        $yaml = Yaml::parse("day: 2027-01-01\ntext: \"TVA < 15 000 €\"\nflag: true\nbrackets:\n  - {from: 0, to: 1620, rate: 0}\n  - {from: 1620, rate: 0.5}", Yaml::PARSE_DATETIME);

        self::assertSame(
            ['day' => '2027-01-01', 'text' => 'TVA < 15 000 €', 'flag' => true, 'brackets' => [['from' => '0', 'to' => '1620', 'rate' => '0'], ['from' => '1620', 'rate' => '0.5']]],
            RegulatoryTree::fromYaml($yaml),
        );
    }

    public function testTheStoredJsonHoldsNoNumberAndReadsBackTheSame(): void
    {
        $tree = RegulatoryTree::fromYaml(['rate' => 6.9, 'base' => 'brut', 'list' => [1, 2.5]]);
        $json = RegulatoryTree::encode($tree);

        self::assertSame('{"rate":"6.9","base":"brut","list":["1","2.5"]}', $json);
        self::assertSame($tree, RegulatoryTree::decode($json));
    }

    public function testAnObjectIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RegulatoryTree::fromYaml(['x' => new \stdClass()]);
    }

    public function testLeavesComeDepthFirstWithTheirPath(): void
    {
        $leaves = RegulatoryTree::leaves(['normal' => '20', 'brackets' => [['from' => '0', 'rate' => '1.5']], 'note' => null]);

        self::assertSame([['normal'], ['brackets', 0, 'from'], ['brackets', 0, 'rate'], ['note']], array_map(static fn ($leaf) => $leaf->path, $leaves));
        self::assertSame(['20', '0', '1.5', null], array_map(static fn ($leaf) => $leaf->value, $leaves));
        self::assertSame('brackets › n° 1 › rate', $leaves[2]->pathLabel());
        self::assertTrue($leaves[0]->isNumber());
        self::assertFalse($leaves[3]->isNumber());
        self::assertSame([[]], array_map(static fn ($leaf) => $leaf->path, RegulatoryTree::leaves('12.02')));
    }

    public function testWithLeavesKeepsTheShape(): void
    {
        $tree = ['normal' => '20', 'reduced' => ['a' => '5.5', 'b' => '2.1']];

        self::assertSame(['normal' => '21', 'reduced' => ['a' => '5.5', 'b' => '2']], RegulatoryTree::withLeaves($tree, ['21', '5.5', '2']));
        self::assertSame('12.31', RegulatoryTree::withLeaves('12.02', ['12.31']));
    }

    public function testWithLeavesWantsOneValuePerLeaf(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RegulatoryTree::withLeaves(['a' => '1', 'b' => '2'], ['1']);
    }

    public function testNumbersAreRecognisedByTheirText(): void
    {
        foreach (['0', '12.02', '-2.5', '48060', '0.3193'] as $number) {
            self::assertTrue(RegulatoryTree::isNumber($number), $number);
        }
        foreach (['', '12,02', '01', '1e5', '2027-01-01', 'brut', '.5', true, null] as $text) {
            self::assertFalse(RegulatoryTree::isNumber($text), var_export($text, true));
        }
    }

    public function testAtFollowsAPath(): void
    {
        $tree = ['sales' => ['base' => '85000'], 'list' => [['rate' => '20']]];

        self::assertSame('85000', RegulatoryTree::at($tree, ['sales', 'base']));
        self::assertSame('20', RegulatoryTree::at($tree, ['list', 0, 'rate']));
        self::assertSame(['base' => '85000'], RegulatoryTree::at($tree, ['sales']));
        self::assertNull(RegulatoryTree::at($tree, ['sales', 'nope']));
        self::assertNull(RegulatoryTree::at('12', ['x']));
    }

    public function testAListOfBracketsReadsAsATableWithItsOpenEnd(): void
    {
        $table = RegulatoryTree::asTable([['from' => '0', 'to' => '1620', 'rate' => '0'], ['from' => '1620', 'rate' => '0.5']]);

        self::assertSame(['columns' => ['from', 'to', 'rate'], 'rows' => [['0', '1620', '0'], ['1620', null, '0.5']]], $table);
        self::assertNull(RegulatoryTree::asTable(['normal' => '20']), 'A map is not a list of brackets.');
        self::assertNull(RegulatoryTree::asTable(['1', '2']), 'Nor a list of plain values.');
        self::assertNull(RegulatoryTree::asTable([['a' => ['nested' => '1']]]), 'Nor brackets holding structures.');
        self::assertNull(RegulatoryTree::asTable('12'));
    }
}
