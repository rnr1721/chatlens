<?php

declare(strict_types=1);

namespace ChatLens\Tests\Collect;

use ChatLens\Collect\UserMessageCollector;
use ChatLens\Filter\AllUsersFilter;
use ChatLens\Filter\IdListFilter;
use ChatLens\Telegram\TelegramExportReader;
use PHPUnit\Framework\TestCase;

final class UserMessageCollectorTest extends TestCase
{
    private const DEMO = __DIR__ . '/../../examples/demo-chat.json';

    public function testCollectsAuthoredTextMessagesOnly(): void
    {
        $reader = new TelegramExportReader(self::DEMO);
        $result = (new UserMessageCollector())->collect($reader->messages(), new AllUsersFilter());

        $ids = array_map(fn ($u) => $u->id, $result->users);

        self::assertContains('channel2001', $ids);       // channel authors are kept separately
        self::assertNotContains('user', $ids);
        self::assertSame(32, $result->scannedMessages);  // incl. a service message and a photo without text
        self::assertSame(30, $result->matchedMessages);
    }

    public function testReadsFormattedTextArrays(): void
    {
        $reader = new TelegramExportReader(self::DEMO);
        $result = (new UserMessageCollector())->collect($reader->messages(), new IdListFilter(['channel2001']));

        self::assertSame(
            'Reminder: the community clean-up is on Sunday. Commercial posts only on Fridays.',
            $result->users[0]->messages[0]
        );
    }

    public function testKeepsTheLastMessagesByDefault(): void
    {
        $reader = new TelegramExportReader(self::DEMO);
        $result = (new UserMessageCollector(maxMessagesPerUser: 2))->collect($reader->messages(), new IdListFilter(['1002']));
        $mark = $result->users[0];

        self::assertSame(4, $mark->totalMessages);
        self::assertCount(2, $mark->messages);
        self::assertStringStartsWith('Fine, I\'ll stay', $mark->messages[1]);
    }

    public function testKeepsTheFirstMessagesWhenAsked(): void
    {
        $reader = new TelegramExportReader(self::DEMO);
        $result = (new UserMessageCollector(maxMessagesPerUser: 1, take: 'first'))->collect($reader->messages(), new IdListFilter(['user1002']));

        self::assertStringStartsWith('Again an ad', $result->users[0]->messages[0]);
    }

    public function testMinMessagesAndTruncation(): void
    {
        $reader = new TelegramExportReader(self::DEMO);
        $result = (new UserMessageCollector(maxMessageLength: 10, minMessages: 4))->collect($reader->messages(), new AllUsersFilter());

        self::assertSame(['user1002', 'user1004'], array_map(fn ($u) => $u->id, $result->users));
        self::assertSame('Again an a…', $result->users[0]->messages[0]);
        self::assertGreaterThan(0, $result->skippedUsers);
    }

    public function testChatName(): void
    {
        self::assertSame('Green Street Neighbours', (new TelegramExportReader(self::DEMO))->chatName());
    }
}
