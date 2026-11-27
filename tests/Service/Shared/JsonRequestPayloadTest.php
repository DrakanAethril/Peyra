<?php

declare(strict_types=1);

namespace App\Tests\Service\Shared;

use App\Service\Shared\JsonRequestPayload;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class JsonRequestPayloadTest extends TestCase
{
    public function testAMalformedBodyIsAnEmptyPayload(): void
    {
        self::assertTrue(JsonRequestPayload::fromRequest(new Request(content: '{oops'))->isEmpty());
        self::assertTrue(JsonRequestPayload::fromJson('"a string"')->isEmpty());
        self::assertSame([], JsonRequestPayload::listFromJson('null'));
    }

    public function testScalarsAreNarrowedOnce(): void
    {
        $payload = JsonRequestPayload::fromJson('{"label":"Achats","cents":"262200","n":12,"flag":true,"no":"true","list":[1]}');

        self::assertSame('Achats', $payload->string('label'));
        self::assertSame('', $payload->string('list'));
        self::assertSame(262200, $payload->int('cents'));
        self::assertSame(12, $payload->int('n'));
        self::assertNull($payload->int('label'));
        self::assertSame(7, $payload->int('missing', 7));
        self::assertTrue($payload->bool('flag'));
        self::assertFalse($payload->bool('no'), 'Only a JSON boolean is a boolean.');
        self::assertTrue($payload->has('label'));
        self::assertFalse($payload->has('missing'));
    }

    public function testADayOfTheDossier(): void
    {
        $payload = JsonRequestPayload::fromJson('{"date":"2026-11-12","bad":"2026-02-31","french":"12/11/2026"}');

        self::assertSame('2026-11-12', $payload->date('date')?->format('Y-m-d'));
        self::assertNull($payload->date('bad'));
        self::assertNull($payload->date('french'));
        self::assertNull($payload->date('missing'));
    }

    public function testListsKeepOrderAndDropWhatIsNotAnId(): void
    {
        $payload = JsonRequestPayload::fromJson('{"ids":[3,"1","x",2,null],"groups":[[1,"2"],"no",["a",4]],"tags":["a",1,{"b":2}]}');

        self::assertSame([3, 1, 2], $payload->ids());
        self::assertSame([[1, 2], [4]], $payload->intLists('groups'));
        self::assertSame(['a', '1'], $payload->strings('tags'));
        self::assertSame([], $payload->ids('missing'));
    }

    public function testNestedObjects(): void
    {
        $payload = JsonRequestPayload::fromJson('{"line":{"account":"401000"},"lines":[{"debit":100},"x",{"credit":100}]}');

        self::assertSame('401000', $payload->object('line')->string('account'));
        self::assertTrue($payload->object('missing')->isEmpty());
        self::assertCount(2, $payload->objects('lines'));
        self::assertSame(100, $payload->objects('lines')[1]->int('credit'));

        $rows = JsonRequestPayload::listFromJson('[{"a":1},2,{"a":3}]');
        self::assertCount(2, $rows);
        self::assertSame(3, $rows[1]->int('a'));
    }
}
