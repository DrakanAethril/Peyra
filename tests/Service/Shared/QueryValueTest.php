<?php

declare(strict_types=1);

namespace App\Tests\Service\Shared;

use App\Service\Shared\QueryValue;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class QueryValueTest extends TestCase
{
    public function testAnEmptyOrMalformedValueReadsAsAbsent(): void
    {
        $request = new Request(['page' => '', 'q' => ['x'], 'n' => '3', 'name' => ' Inès ']);

        self::assertSame(1, QueryValue::int($request, 'page', 1));
        self::assertSame(1, QueryValue::int($request, 'q', 1));
        self::assertSame(3, QueryValue::int($request, 'n', 1));
        self::assertNull(QueryValue::nullableInt($request, 'page'));
        self::assertSame('', QueryValue::trimmed($request, 'q'));
        self::assertSame('Inès', QueryValue::trimmed($request, 'name'));
        self::assertFalse(QueryValue::bool($request, 'page'));
        self::assertSame([3], QueryValue::intList($request, 'n'));
    }
}
