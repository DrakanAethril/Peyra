<?php

declare(strict_types=1);

namespace App\Tests\Service\Shared;

use App\Service\Shared\PostValue;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/** A posted field read as the type the caller needs; a forged or half-filled form reads as « not provided », never a 400. */
final class PostValueTest extends TestCase
{
    public function testReadsTheShapesItKnows(): void
    {
        $request = Request::create('/', 'POST', [
            'name' => '  BTS CG 1 ',
            'class' => '12',
            'students' => ['3', '7', '7', '0', 'x', ['9']],
            'other' => [4 => 'none', 5 => '12', 'junk' => 'x', 6 => ['no']],
            'group' => ['cg-1' => '3', 'cg-2' => '', 'cg-3' => ['x']],
        ]);

        self::assertSame('BTS CG 1', PostValue::trimmed($request, 'name'));
        self::assertSame(12, PostValue::nullableInt($request, 'class'));
        self::assertSame([3, 7], PostValue::intList($request, 'students'));
        self::assertSame([4 => 'none', 5 => '12'], PostValue::stringsById($request, 'other'));
        self::assertSame(['cg-1' => '3', 'cg-2' => ''], PostValue::stringsByKey($request, 'group'));
    }

    public function testAnythingElseReadsAsNotProvided(): void
    {
        $request = Request::create('/', 'POST', ['name' => ['x'], 'class' => '-3', 'students' => '5', 'other' => 'x', 'group' => 'x']);

        self::assertSame('', PostValue::trimmed($request, 'name'));
        self::assertNull(PostValue::nullableInt($request, 'class'));
        self::assertNull(PostValue::nullableInt($request, 'absent'));
        self::assertSame([5], PostValue::intList($request, 'students'));
        self::assertSame([], PostValue::stringsById($request, 'other'));
        self::assertSame([], PostValue::stringsByKey($request, 'group'));
    }

    public function testReadsATableOfChoicesOffTheQueryString(): void
    {
        $request = Request::create('/?other[4]=none&other[x]=1&other[5]=12');

        self::assertSame([4 => 'none', 5 => '12'], PostValue::queryStringsById($request, 'other'));
    }
}
