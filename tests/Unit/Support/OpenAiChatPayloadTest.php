<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\OpenAiChatPayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class OpenAiChatPayloadTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function modelProvider(): array
    {
        return [
            'gpt-5 flagship' => ['gpt-5', true],
            'gpt-5-mini' => ['gpt-5-mini', true],
            'gpt-5-nano' => ['gpt-5-nano', true],
            'gpt-5.4-mini' => ['gpt-5.4-mini', true],
            'gpt-5.6-sol' => ['gpt-5.6-sol', true],
            'o1' => ['o1', true],
            'o3-mini' => ['o3-mini', true],
            'o4-mini' => ['o4-mini', true],
            'gpt-4o classic' => ['gpt-4o', false],
            'gpt-4o-mini classic' => ['gpt-4o-mini', false],
            'gpt-4.1-nano classic' => ['gpt-4.1-nano', false],
            'empty string' => ['', false],
        ];
    }

    #[Test]
    #[DataProvider('modelProvider')]
    public function it_identifies_reasoning_models(string $model, bool $expected): void
    {
        $this->assertSame($expected, OpenAiChatPayload::isReasoningModel($model));
    }

    #[Test]
    public function it_strips_temperature_and_adds_reasoning_effort_for_reasoning_models(): void
    {
        $payload = OpenAiChatPayload::forModel([
            'model' => 'gpt-5',
            'temperature' => 0.1,
            'max_completion_tokens' => 1400,
        ]);

        $this->assertArrayNotHasKey('temperature', $payload);
        $this->assertSame('low', $payload['reasoning_effort']);
        $this->assertSame(1400, $payload['max_completion_tokens']);
    }

    #[Test]
    public function it_honours_an_explicit_reasoning_effort(): void
    {
        $payload = OpenAiChatPayload::forModel([
            'model' => 'gpt-5-mini',
        ], reasoningEffort: 'minimal');

        $this->assertSame('minimal', $payload['reasoning_effort']);
    }

    #[Test]
    public function it_normalises_minimal_to_none_for_gpt54_and_newer_models(): void
    {
        $payload = OpenAiChatPayload::forModel([
            'model' => 'gpt-5.4-mini',
        ], reasoningEffort: 'minimal');

        $this->assertSame('none', $payload['reasoning_effort']);
    }

    #[Test]
    public function it_does_not_override_a_caller_supplied_reasoning_effort(): void
    {
        $payload = OpenAiChatPayload::forModel([
            'model' => 'gpt-5',
            'reasoning_effort' => 'high',
        ], reasoningEffort: 'low');

        $this->assertSame('high', $payload['reasoning_effort']);
    }

    #[Test]
    public function it_migrates_max_tokens_to_max_completion_tokens(): void
    {
        $payload = OpenAiChatPayload::forModel([
            'model' => 'gpt-5-mini',
            'max_tokens' => 300,
        ]);

        $this->assertArrayNotHasKey('max_tokens', $payload);
        $this->assertSame(300, $payload['max_completion_tokens']);
    }

    #[Test]
    public function it_does_not_clobber_an_existing_max_completion_tokens_when_migrating(): void
    {
        $payload = OpenAiChatPayload::forModel([
            'model' => 'gpt-4o',
            'max_tokens' => 300,
            'max_completion_tokens' => 1200,
        ]);

        $this->assertArrayNotHasKey('max_tokens', $payload);
        $this->assertSame(1200, $payload['max_completion_tokens']);
    }

    #[Test]
    public function it_passes_classic_models_through_with_temperature_intact(): void
    {
        $payload = OpenAiChatPayload::forModel([
            'model' => 'gpt-4o',
            'temperature' => 0.1,
            'max_completion_tokens' => 1400,
        ]);

        $this->assertSame(0.1, $payload['temperature']);
        $this->assertArrayNotHasKey('reasoning_effort', $payload);
        $this->assertSame(1400, $payload['max_completion_tokens']);
    }

    #[Test]
    public function it_removes_an_empty_service_tier(): void
    {
        $payload = OpenAiChatPayload::forModel([
            'model' => 'gpt-5.6-terra',
            'service_tier' => null,
        ]);

        $this->assertArrayNotHasKey('service_tier', $payload);
    }
}
