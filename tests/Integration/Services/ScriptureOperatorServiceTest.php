<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Data\ApiBiblePassageResult;
use App\Models\ScripturePassage;
use App\Models\Sermon;
use App\Services\Scripture\ApiBibleClient;
use App\Services\Scripture\ScriptureHtmlSanitizer;
use App\Services\Scripture\ScriptureOperatorService;
use App\Services\Scripture\ScriptureReferenceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

class ScriptureOperatorServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sermon::query()->delete();
        ScripturePassage::query()->delete();

        Config::set('services.api_bible.enabled', true);
        Config::set('services.api_bible.default_bible_id', 'de4e12af7f28f599-02');
        Config::set('services.api_bible.refresh_after_days', 28);
        Config::set('services.api_bible.daily_budget', 5000);
        Cache::flush();
    }

    /**
     * @return MockObject&ApiBibleClient
     */
    private function mockClientWithBudget(): MockObject
    {
        $client = $this->createMock(ApiBibleClient::class);
        $client->method('hasDailyBudget')->willReturn(true);

        return $client;
    }

    /**
     * @param  array<string, string|null>  $normalizedReferences
     */
    private function mockResolver(array $normalizedReferences): void
    {
        $resolver = $this->createMock(ScriptureReferenceResolver::class);
        $resolver->method('normalize')
            ->willReturnCallback(static fn (string $reference): ?string => $normalizedReferences[$reference] ?? null);

        $this->app->instance(ScriptureReferenceResolver::class, $resolver);
    }

    private function mockSanitizer(): void
    {
        $sanitizer = $this->createMock(ScriptureHtmlSanitizer::class);
        $sanitizer->method('sanitize')
            ->willReturnCallback(static fn (?string $html): ?string => $html);

        $this->app->instance(ScriptureHtmlSanitizer::class, $sanitizer);
    }

    public function test_run_enrichment_processes_sermons_through_shared_service(): void
    {
        $sermon = Sermon::factory()->create([
            'reference' => 'John 3:16',
            'scripture_passage_id' => null,
        ]);
        Sermon::factory()->create([
            'reference' => 'xyzzy 99:99',
            'scripture_passage_id' => null,
        ]);

        $client = $this->mockClientWithBudget();
        $this->mockResolver([
            'John 3:16' => 'John 3:16',
            'xyzzy 99:99' => null,
        ]);
        $this->mockSanitizer();
        $client->expects($this->once())
            ->method('searchPassage')
            ->with('John 3:16')
            ->willReturn(new ApiBiblePassageResult(
                passageId: 'JHN.3.16',
                displayReference: 'John 3:16',
                htmlContent: '<p>For God so loved the world.</p>',
                copyright: 'NIV',
                fumsToken: 'tok',
            ));
        $this->app->instance(ApiBibleClient::class, $client);

        $result = app(ScriptureOperatorService::class)->runEnrichment(delayMs: 0);

        $this->assertSame(1, $result['summary']['resolved']);
        $this->assertSame(1, $result['summary']['unparseable']);
        $this->assertSame(0, $result['summary']['failed']);
        $this->assertNotNull($sermon->fresh()->scripture_passage_id);
    }

    public function test_run_refresh_updates_stale_passages_through_shared_service(): void
    {
        $passage = ScripturePassage::factory()->stale()->create([
            'api_passage_id' => 'JHN.3.16',
            'normalized_reference' => 'John 3:16',
        ]);

        $client = $this->mockClientWithBudget();
        $this->mockSanitizer();
        $client->expects($this->once())
            ->method('fetchPassageById')
            ->with('JHN.3.16')
            ->willReturn(new ApiBiblePassageResult(
                passageId: 'JHN.3.16',
                displayReference: 'John 3:16',
                htmlContent: '<p>Fresh content.</p>',
                copyright: 'NIV',
                fumsToken: 'fresh-token',
            ));
        $this->app->instance(ApiBibleClient::class, $client);

        $result = app(ScriptureOperatorService::class)->runRefresh(delayMs: 0);

        $this->assertSame(1, $result['summary']['updated']);
        $this->assertSame(0, $result['summary']['failed']);
        $this->assertSame('fresh-token', $passage->fresh()->fums_token);
    }
}
