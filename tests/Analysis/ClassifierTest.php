<?php

declare(strict_types=1);

namespace ChatLens\Tests\Analysis;

use ChatLens\Analysis\Classifier;
use ChatLens\Analysis\ResultCache;
use ChatLens\Collect\ChatUser;
use ChatLens\Llm\LlmException;
use ChatLens\Llm\LlmProviderInterface;
use ChatLens\Llm\LlmRequest;
use ChatLens\Llm\LlmResponse;
use ChatLens\Llm\OutputTruncatedException;
use ChatLens\Profile\ProfileLoader;
use ChatLens\Profile\Profile;
use PHPUnit\Framework\TestCase;

final class ClassifierTest extends TestCase
{
    private string $cacheDir;
    private Profile $profile;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/chatlens-test-' . bin2hex(random_bytes(4));
        $this->profile = (new ProfileLoader(__DIR__))->fromArray('test', [
            'prompt' => 'Classify {{chat_name}}',
            'categories' => [
                'yes' => 'likes it',
                'no' => 'dislikes it',
                'unclear' => 'no idea',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->cacheDir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->cacheDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->cacheDir);
        }
    }

    public function testClassifiesAndMapsAliasesBackToRealIds(): void
    {
        $provider = new ScriptedProvider(static fn (array $users) => array_map(
            static fn ($u) => ['id' => $u['id'], 'category' => 'yes', 'reason' => 'ok', 'quotes' => [$u['messages'][0]]],
            $users
        ));

        $run = $this->classifier($provider, chunk: 2)->classify($this->users(3), $this->profile, 'Chat');

        self::assertSame(['user1', 'user2', 'user3'], array_map(fn ($c) => $c->userId, $run->classifications));
        self::assertSame('yes', $run->classifications[0]->category);
        self::assertTrue($run->classifications[0]->quotes[0]->verified);
        self::assertSame(2, $run->stats->requests);
        self::assertSame([], $run->failed);
    }

    public function testSecondRunIsServedFromCacheEvenWithDifferentChunkSize(): void
    {
        $provider = new ScriptedProvider(static fn (array $users) => array_map(
            static fn ($u) => ['id' => $u['id'], 'category' => 'no', 'reason' => 'r', 'quotes' => []],
            $users
        ));

        $this->classifier($provider, chunk: 2)->classify($this->users(4), $this->profile, 'Chat');
        $run = $this->classifier($provider, chunk: 3)->classify($this->users(4), $this->profile, 'Chat');

        self::assertSame(0, $run->stats->requests);
        self::assertSame(4, $run->stats->cacheHits);
        self::assertSame(2, $provider->calls);
    }

    public function testSplitsBatchWhenOutputIsTruncated(): void
    {
        $provider = new ScriptedProvider(static function (array $users) {
            if (count($users) > 2) {
                throw new OutputTruncatedException('too long');
            }
            return array_map(static fn ($u) => ['id' => $u['id'], 'category' => 'yes', 'reason' => 'r', 'quotes' => []], $users);
        });

        $run = $this->classifier($provider, chunk: 8)->classify($this->users(8), $this->profile, 'Chat');

        self::assertCount(8, $run->classifications);
        self::assertSame(3, $run->stats->splits); // 8 → 4+4 → 2+2+2+2
    }

    public function testRetriesUsersTheModelSkipped(): void
    {
        $provider = new ScriptedProvider(static function (array $users) {
            // first answer drops the last user of every batch bigger than one
            if (count($users) > 1) {
                array_pop($users);
            }
            return array_map(static fn ($u) => ['id' => $u['id'], 'category' => 'yes', 'reason' => 'r', 'quotes' => []], $users);
        });

        $run = $this->classifier($provider, chunk: 4, retryChunk: 1)->classify($this->users(4), $this->profile, 'Chat');

        self::assertCount(4, $run->classifications);
        self::assertSame(1, $run->stats->retried);
    }

    public function testReportsUsersThatFailCompletely(): void
    {
        $provider = new ScriptedProvider(static fn () => throw new LlmException('HTTP 500'));

        $run = $this->classifier($provider)->classify($this->users(2), $this->profile, 'Chat');

        self::assertSame([], $run->classifications);
        self::assertCount(2, $run->failed);
    }

    public function testUnknownCategoryFallsBackAndFakeQuotesAreMarked(): void
    {
        $provider = new ScriptedProvider(static fn (array $users) => array_map(
            static fn ($u) => ['id' => $u['id'], 'category' => 'maybe', 'reason' => 'r', 'quotes' => ['never said this']],
            $users
        ));

        $run = $this->classifier($provider)->classify($this->users(1), $this->profile, 'Chat');
        $c = $run->classifications[0];

        self::assertSame('unclear', $c->category);
        self::assertTrue($c->categoryCorrected);
        self::assertFalse($c->quotes[0]->verified);
        self::assertSame(1, $run->stats->unverifiedQuotes);
    }

    public function testIgnoresIdsThatWereNotInTheBatch(): void
    {
        $provider = new ScriptedProvider(static fn () => [
            ['id' => 'u1', 'category' => 'yes', 'reason' => 'r', 'quotes' => []],
            ['id' => 'u99', 'category' => 'no', 'reason' => 'hallucinated', 'quotes' => []],
        ]);

        $run = $this->classifier($provider)->classify($this->users(1), $this->profile, 'Chat');

        self::assertCount(1, $run->classifications);
    }

    private function classifier(LlmProviderInterface $provider, int $chunk = 8, int $retryChunk = 3): Classifier
    {
        return new Classifier(
            provider: $provider,
            cache: new ResultCache($this->cacheDir),
            chunkSize: $chunk,
            retryChunkSize: $retryChunk,
            delayMs: 0,
        );
    }

    /** @return list<ChatUser> */
    private function users(int $n): array
    {
        $users = [];
        for ($i = 1; $i <= $n; $i++) {
            $users[] = new ChatUser("user{$i}", "User {$i}", ["message number {$i}"], 1);
        }

        return $users;
    }
}

/**
 * Test double: decodes the users payload and lets a closure decide the answer.
 */
final class ScriptedProvider implements LlmProviderInterface
{
    public int $calls = 0;

    /** @param \Closure(list<array<string, mixed>>): list<array<string, mixed>> $script */
    public function __construct(private readonly \Closure $script)
    {
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $this->calls++;
        $users = json_decode($request->user, true)['users'];

        return new LlmResponse(json_encode(['users' => ($this->script)($users)]));
    }

    public function name(): string
    {
        return 'scripted';
    }

    public function model(): string
    {
        return 'test';
    }
}
