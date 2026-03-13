<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Data\ApiBiblePassageResult;
use App\Jobs\FetchBibleTextForSermon;
use App\Models\ScripturePassage;
use App\Models\Sermon;
use App\Services\ApiBibleClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

class EnrichSermonsScriptureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.api_bible.enabled', true);
        Config::set('services.api_bible.default_bible_id', 'de4e12af7f28f599-02');
        Config::set('services.api_bible.refresh_after_days', 28);
        Config::set('services.api_bible.daily_budget', 5000);
        Cache::flush();
    }

    /**
     * Returns a mock ApiBibleClient with hasDailyBudget() returning true by default,
     * so tests that exercise normal paths don't hit the budget guard unexpectedly.
     *
     * @return MockObject&ApiBibleClient
     */
    private function mockClientWithBudget(): MockObject
    {
        $client = $this->createMock(ApiBibleClient::class);
        $client->method('hasDailyBudget')->willReturn(true);

        return $client;
    }

    public function test_skips_when_feature_disabled(): void
    {
        Config::set('services.api_bible.enabled', false);
        Sermon::factory()->create(['reference' => 'John 3:16', 'scripture_passage_id' => null]);

        $this->artisan('sermons:enrich-scripture')
            ->expectsOutput('api.bible is disabled (API_BIBLE_ENABLED=false). Skipping.')
            ->assertExitCode(0);
    }

    public function test_reports_no_sermons_when_all_enriched(): void
    {
        $passage = ScripturePassage::factory()->create();
        Sermon::factory()->create(['reference' => 'John 3:16', 'scripture_passage_id' => $passage->id]);

        $this->artisan('sermons:enrich-scripture')
            ->expectsOutput('No sermons need scripture enrichment.')
            ->assertExitCode(0);
    }

    public function test_dry_run_lists_sermons_without_calling_api(): void
    {
        Sermon::factory()->create(['reference' => 'John 3:16', 'scripture_passage_id' => null]);

        $client = $this->mockClientWithBudget();
        $client->expects($this->never())->method('searchPassage');
        $this->app->instance(ApiBibleClient::class, $client);

        $this->artisan('sermons:enrich-scripture', ['--dry-run' => true, '--delay' => 0])
            ->assertExitCode(0);
    }

    public function test_resolves_and_reports_result_categories(): void
    {
        Sermon::factory()->create(['reference' => 'John 3:16', 'scripture_passage_id' => null]);
        Sermon::factory()->create(['reference' => 'xyzzy 99:99', 'scripture_passage_id' => null]);

        $apiResult = new ApiBiblePassageResult(
            passageId: 'JHN.3.16',
            displayReference: 'John 3:16',
            htmlContent: '<p>For God so loved the world.</p>',
            copyright: 'NIV',
            fumsToken: 'tok',
        );

        $client = $this->mockClientWithBudget();
        $client->expects($this->once())->method('searchPassage')->willReturn($apiResult);
        $this->app->instance(ApiBibleClient::class, $client);

        $this->artisan('sermons:enrich-scripture', ['--delay' => 0])
            ->expectsOutputToContain('Resolved: 1, Not found: 0, Unparseable: 1')
            ->assertExitCode(0);
    }

    public function test_reports_not_found_when_api_returns_null(): void
    {
        Sermon::factory()->create(['reference' => 'John 3:16', 'scripture_passage_id' => null]);

        $client = $this->mockClientWithBudget();
        $client->expects($this->once())->method('searchPassage')->willReturn(null);
        $this->app->instance(ApiBibleClient::class, $client);

        $this->artisan('sermons:enrich-scripture', ['--delay' => 0])
            ->expectsOutputToContain('Not found: 1')
            ->assertExitCode(0);
    }

    public function test_reports_rate_limited_on_runtime_exception(): void
    {
        Sermon::factory()->create(['reference' => 'John 3:16', 'scripture_passage_id' => null]);

        $client = $this->mockClientWithBudget();
        $client->expects($this->once())
            ->method('searchPassage')
            ->willThrowException(new \RuntimeException('api.bible search failed with status 429 after retries'));
        $this->app->instance(ApiBibleClient::class, $client);

        $this->artisan('sermons:enrich-scripture', ['--delay' => 0])
            ->expectsOutputToContain('Rate-limited: 1')
            ->assertExitCode(0);
    }

    public function test_stops_when_daily_budget_exceeded(): void
    {
        Config::set('services.api_bible.daily_budget', 1);

        // Pre-fill the budget cache so hasDailyBudget() on the real client returns false
        Cache::put('api_bible_daily_calls_'.now()->format('Y-m-d'), 1, now()->endOfDay());

        Sermon::factory()->count(3)->create(['reference' => 'John 3:16', 'scripture_passage_id' => null]);

        // Use the real client so hasDailyBudget() reads from the cache
        $this->artisan('sermons:enrich-scripture', ['--delay' => 0])
            ->expectsOutputToContain('Daily API budget')
            ->assertExitCode(0);
    }

    public function test_queue_mode_dispatches_jobs_without_calling_api(): void
    {
        Bus::fake();

        Sermon::factory()->count(2)->create(['reference' => 'John 3:16', 'scripture_passage_id' => null]);

        $this->artisan('sermons:enrich-scripture', ['--queue' => true])
            ->expectsOutputToContain('Queued: 2')
            ->assertExitCode(0);

        Bus::assertDispatched(FetchBibleTextForSermon::class, 2);
    }

    public function test_reuses_fresh_cached_passage_without_api_call(): void
    {
        $existing = ScripturePassage::factory()->create([
            'bible_id' => 'de4e12af7f28f599-02',
            'normalized_reference' => 'John 3:16',
            'fetched_at' => now()->subDays(5),
        ]);

        $sermon = Sermon::factory()->create(['reference' => 'John 3:16', 'scripture_passage_id' => null]);

        $client = $this->mockClientWithBudget();
        $client->expects($this->never())->method('searchPassage');
        $this->app->instance(ApiBibleClient::class, $client);

        $this->artisan('sermons:enrich-scripture', ['--delay' => 0])
            ->expectsOutputToContain('Resolved: 1')
            ->assertExitCode(0);

        $this->assertSame($existing->id, $sermon->fresh()->scripture_passage_id);
    }
}
