<?php

declare(strict_types=1);

namespace ChatLens\Tests\Analysis;

use ChatLens\Analysis\JsonRepair;
use PHPUnit\Framework\TestCase;

final class JsonRepairTest extends TestCase
{
    private JsonRepair $repair;

    protected function setUp(): void
    {
        $this->repair = new JsonRepair();
    }

    public function testParsesValidJson(): void
    {
        $r = $this->repair->parseUsers('{"users":[{"id":"u1","category":"loyal","reason":"x","quotes":[]}]}');

        self::assertSame(JsonRepair::DIRECT, $r['strategy']);
        self::assertSame('u1', $r['users'][0]['id']);
    }

    public function testStripsMarkdownFencesAndChatter(): void
    {
        $raw = "Sure! Here you go:\n```json\n{\"users\":[{\"id\":\"u1\",\"category\":\"loyal\"}]}\n```\nHope it helps";
        $r = $this->repair->parseUsers($raw);

        self::assertSame('u1', $r['users'][0]['id']);
    }

    public function testRemovesTrailingCommasButNotInsideStrings(): void
    {
        $raw = '{"users":[{"id":"u1","reason":"a, ]b,}","quotes":["x",],},]}';
        $r = $this->repair->parseUsers($raw);

        self::assertNotNull($r);
        self::assertSame('a, ]b,}', $r['users'][0]['reason']);
    }

    public function testClosesTruncatedJson(): void
    {
        $raw = '{"users":[{"id":"u1","category":"loyal","reason":"fine"},{"id":"u2","category":"unclear","reason":"cut her';
        $r = $this->repair->parseUsers($raw);

        self::assertSame(JsonRepair::CLOSE_BRACKETS, $r['strategy']);
        self::assertCount(2, $r['users']);
    }

    public function testSalvagesCompleteObjectsFromBrokenArray(): void
    {
        $raw = '{"users":[{"id":"u1","category":"loyal"},{"id":"u2","category": loyal BROKEN},{"id":"u3","category":"unclear"}';
        $r = $this->repair->parseUsers($raw);

        self::assertSame(JsonRepair::SALVAGE, $r['strategy']);
        self::assertSame(['u1', 'u3'], array_column($r['users'], 'id'));
    }

    public function testAcceptsBareList(): void
    {
        $r = $this->repair->parseUsers('{"0":{"id":"u1"},"1":{"id":"u2"}}');

        self::assertCount(2, $r['users']);
    }

    public function testReturnsNullForGarbage(): void
    {
        self::assertNull($this->repair->parseUsers('I cannot help with that.'));
        self::assertNull($this->repair->parseUsers('{"foo":"bar"}'));
    }
}
