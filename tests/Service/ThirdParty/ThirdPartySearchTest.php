<?php

declare(strict_types=1);

namespace App\Tests\Service\ThirdParty;

use App\Service\ThirdParty\ThirdPartySearch;
use PHPUnit\Framework\TestCase;

/** How a third party is found by the F4 picker and the list's search box. */
final class ThirdPartySearchTest extends TestCase
{
    private const array LIST = [
        ['code' => 'C0008', 'auxiliary' => '411DUMA', 'name' => 'Atelier Dumas EURL'],
        ['code' => 'C0014', 'auxiliary' => '411TISS', 'name' => 'Atelier d\'architecture Tissot'],
        ['code' => 'F0001', 'auxiliary' => '401SCIE', 'name' => 'Scierie du Haut-Forez'],
        ['code' => 'F0010', 'auxiliary' => '401ELEC', 'name' => 'Électricité Loire Énergies'],
        ['code' => 'O0001', 'auxiliary' => null, 'name' => 'Urssaf Rhône-Alpes'],
    ];

    public function testByCodeAuxiliaryCodeOrName(): void
    {
        self::assertSame(['F0001'], $this->codes('401sc'));
        self::assertSame(['F0001', 'F0010'], $this->codes('401'));
        self::assertSame(['C0008', 'C0014'], $this->codes('C00'));
        self::assertSame(['C0008', 'C0014'], $this->codes('atelier'));
        self::assertSame(['C0008'], $this->codes('atelier dumas'));
        self::assertSame(['F0010'], $this->codes('electricite'), 'Sans accent ni casse.');
        self::assertSame(['O0001'], $this->codes('RHONE'));
        self::assertSame([], $this->codes('zzz'));
        self::assertCount(5, $this->codes('  '));
        self::assertSame(['C0008'], $this->codes('a', 1));
    }

    /** @return list<string> */
    private function codes(string $query, int $limit = 0): array
    {
        return array_map(static fn (array $t): string => $t['code'], ThirdPartySearch::filter(self::LIST, static fn (array $t): array => $t, $query, $limit));
    }
}
