<?php

declare(strict_types=1);

namespace ChatLens\Tests\Profile;

use ChatLens\Analysis\PromptBuilder;
use ChatLens\Profile\ProfileLoader;
use PHPUnit\Framework\TestCase;

final class ProfileTest extends TestCase
{
    private ProfileLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new ProfileLoader(__DIR__ . '/../../profiles');
    }

    public function testAllBundledProfilesAreValid(): void
    {
        $available = $this->loader->available();

        self::assertArrayHasKey('ad-loyalty', $available);
        self::assertArrayNotHasKey('_template', $available);

        foreach ($available as $file) {
            self::assertGreaterThanOrEqual(2, count($this->loader->load($file)->categories));
        }
        // the template must stay loadable too
        self::assertSame('unclear', $this->loader->load(__DIR__ . '/../../profiles/_template.yaml')->fallback);
    }

    public function testLocalizedLabelsFallBackToEnglish(): void
    {
        $p = $this->loader->load('ad-loyalty');

        self::assertSame('Нелояльные', $p->category('not_loyal')->label('ru'));
        self::assertSame('Not loyal', $p->category('not_loyal')->label('de'));
    }

    public function testRejectsUnknownFallback(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->loader->fromArray('x', ['prompt' => 'p', 'categories' => ['a' => 'A', 'b' => 'B'], 'fallback' => 'c']);
    }

    public function testRejectsBadCategoryKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->loader->fromArray('x', ['prompt' => 'p', 'categories' => ['Not OK' => 'A', 'b' => 'B']]);
    }

    public function testPromptContainsCategoriesAndContract(): void
    {
        $p = $this->loader->load('cats');
        $system = (new PromptBuilder())->system($p, 'My Chat', 'Ukrainian');

        self::assertStringContainsString('«My Chat»', $system);
        self::assertStringContainsString('"dislikes" —', $system);
        self::assertStringContainsString('written in Ukrainian', $system);
        self::assertStringContainsString('use "unclear"', $system);

        $schema = (new PromptBuilder())->responseSchema($p);
        self::assertSame(['likes', 'neutral', 'dislikes', 'unclear'], $schema['properties']['users']['items']['properties']['category']['enum']);
    }
}
