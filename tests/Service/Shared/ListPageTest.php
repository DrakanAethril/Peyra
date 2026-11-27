<?php

declare(strict_types=1);

namespace App\Tests\Service\Shared;

use App\Service\Shared\ListPage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class ListPageTest extends TestCase
{
    public function testDefaultsAndOffsets(): void
    {
        $page = ListPage::fromRequest(new Request(), 'ledger.entries');

        self::assertSame(1, $page->page);
        self::assertSame(50, $page->perPage);
        self::assertSame(0, $page->offset());
        self::assertSame(100, ListPage::of(3, 50)->offset());
        self::assertSame(50, ListPage::of(1, 1000)->perPage, 'A size outside 25/50/100 is ignored.');
        self::assertSame(1, ListPage::of(-4)->page);
    }

    public function testEmptyOrForgedValuesReadAsNotGiven(): void
    {
        $page = ListPage::fromRequest(new Request(['page' => '', 'per' => '']), 'x');
        self::assertSame(1, $page->page);
        self::assertSame(50, $page->perPage);

        $page = ListPage::fromRequest(new Request(['page' => ['1'], 'per' => '5000']), 'x');
        self::assertSame(1, $page->page);
        self::assertSame(50, $page->perPage);
    }

    public function testTheSizeIsRememberedPerScreen(): void
    {
        $session = new Session(new MockArraySessionStorage());

        $asked = $this->request(['per' => '100', 'page' => '2'], $session);
        self::assertSame(100, ListPage::fromRequest($asked, 'ledger.entries')->perPage);

        self::assertSame(100, ListPage::fromRequest($this->request([], $session), 'ledger.entries')->perPage);
        self::assertSame(50, ListPage::fromRequest($this->request([], $session), 'thirdparty.list')->perPage, 'Another screen keeps its own size.');

        self::assertSame(25, ListPage::fromRequest($this->request(['per' => '25'], $session), 'ledger.entries')->perPage);
        self::assertSame(25, ListPage::fromRequest($this->request([], $session), 'ledger.entries')->perPage);
    }

    public function testWithinBringsAPageBackToTheLastOne(): void
    {
        // 431 rows by 50: 9 pages.
        self::assertSame(9, ListPage::of(12, 50)->within(431)->page);
        self::assertSame(4, ListPage::of(4, 50)->within(431)->page);
        self::assertSame(1, ListPage::of(3, 25)->within(0)->page);
        self::assertSame(2, ListPage::of(5, 25)->within(50)->page);
    }

    /** @param array<string, string> $query */
    private function request(array $query, Session $session): Request
    {
        $request = new Request($query);
        $request->setSession($session);

        return $request;
    }
}
