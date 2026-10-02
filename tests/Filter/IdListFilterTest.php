<?php

declare(strict_types=1);

namespace ChatLens\Tests\Filter;

use ChatLens\Filter\IdListFilter;
use ChatLens\Telegram\UserId;
use PHPUnit\Framework\TestCase;

final class IdListFilterTest extends TestCase
{
    public function testUserAndChannelWithSameDigitsAreDifferent(): void
    {
        $filter = new IdListFilter(['user123']);

        self::assertTrue($filter->accepts('user123'));
        self::assertFalse($filter->accepts('channel123'));
    }

    public function testBareNumbersAreUsers(): void
    {
        self::assertSame('user8065631404', UserId::normalize('8065631404'));
        self::assertSame('user42', UserId::normalize(42));
        self::assertSame('channel7', UserId::normalize(' Channel7 '));
    }

    public function testReadsAllSupportedFileFormats(): void
    {
        $dir = sys_get_temp_dir();

        $json = tempnam($dir, 'ids');
        file_put_contents($json, '["user1", 2]');
        self::assertSame(2, IdListFilter::fromFile($json)->count());

        $objects = tempnam($dir, 'ids');
        file_put_contents($objects, '[{"id":"user1","name":"A","messages":3},{"id":"channel5"}]');
        $f = IdListFilter::fromFile($objects);
        self::assertTrue($f->accepts('channel5'));

        $text = tempnam($dir, 'ids');
        file_put_contents($text, "# my list\n123\n\nuser456\n");
        $f = IdListFilter::fromFile($text);
        self::assertTrue($f->accepts('user123'));
        self::assertTrue($f->accepts('user456'));
        self::assertSame(2, $f->count());

        array_map('unlink', [$json, $objects, $text]);
    }
}
