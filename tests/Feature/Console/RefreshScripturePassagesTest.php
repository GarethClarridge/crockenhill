<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Data\ApiBiblePassageResult;
use App\Models\ScripturePassage;
use App\Services\ApiBibleClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class RefreshScripturePassagesTest extends TestCase
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

    public function test_skips_when_feature_disabled(): void
    {
        Config::set('services.api_bible.enabled', false);

        $this->artisan('scripture:refresh-passages')
            ->expectsOutput('api.bible is disabled (API_BIBLE_ENABLED=false). Skipping.')
            ->assertExitCode(0);
    }

    public function test_reports_nothing_to_refresh_when_all_fresh(): void
    {
        ScripturePassage::factory()->create(['fetched_at' => now()->subDays(5)]);

        $this->artisan('scripture:refresh-passages')
            ->expectsOutput('No stale passages to refresh.')
            ->assertExitCode(0);
    }

    public function test_updates_stale_passage_and_reports_updated(): void
    {
        $passage = ScripturePassage::factory()->stale()->create([
            'api_passage_id' => 'JHN.3.16',
            'normalized_reference' => 'John 3:16',
        ]);

        $apiResult = new ApiBiblePassageResult(
            passageId: 'JHN.3.16',
            displayReference: 'John 3:16',
            htmlContent: '<p>Fresh content.</p>',
            copyright: 'NIV',
            fumsToken: 'new-tok',
        );

        $client = $this->createMock(ApiBibleClient::class);
        $client->expects($this->once())->method('fetchPassageById')->with('JHN.3.16')->willReturn($apiResult);
        $this->app->instance(ApiBibleClient::class, $client);

        $this->artisan('scripture:refresh-passages', ['--delay' => 0])
            ->expectsOutputToContain('Updated: 1')
            ->assertExitCode(0);

        $passage->refresh();
        $this->assertSame('new-tok', $passage->fums_token);
        $this->assertTrue($passage->fetched_at->isToday());
    }

    public function test_reports_not_found_when_api_returns_null(): void
    {
        ScripturePassage::factory()->stale()->create(['api_passage_id' => 'JHN.3.16']);

        $client = $this->createMock(ApiBibleClient::class);
        $client->expects($this->once())->method('fetchPassageById')->willReturn(null);
        $this->app->instance(ApiBibleClient::class, $client);

        $this->artisan('scripture:refresh-passages', ['--delay' => 0])
            ->expectsOutputToContain('Not found: 1')
            ->assertExitCode(0);
    }

    public function test_reports_rate_limited_on_runtime_exception(): void
    {
        ScripturePassage::factory()->stale()->create(['api_passage_id' => 'JHN.3.16']);

        $client = $this->createMock(ApiBibleClient::class);
        $client->expects($this->once())
            ->method('fetchPassageById')
            ->willThrowException(new \RuntimeException('api.bible passage fetch failed with status 429 after retries'));
        $this->app->instance(ApiBibleClient::class, $client);

        $this->artisan('scripture:refresh-passages', ['--delay' => 0])
            ->expectsOutputToContain('Rate-limited: 1')
            ->assertExitCode(0);
    }

    public function test_stops_when_daily_budget_exceeded(): void
    {
        Config::set('services.api_bible.daily_budget', 1);
        Cache::put('api_bible_daily_calls_'.now()->format('Y-m-d'), 1, now()->endOfDay());

        foreach (['John 1:1', 'John 1:2', 'John 1:3'] as $ref) {
            ScripturePassage::factory()->stale()->create([
                'normalized_reference' => $ref,
                'api_passage_id' => str_replace([' ', ':'], ['.', '.'], $ref),
            ]);
        }

        $client = $this->createMock(ApiBibleClient::class);
        $client->expects($this->never())->method('fetchPassageById');
        $this->app->instance(ApiBibleClient::class, $client);

        $this->artisan('scripture:refresh-passages', ['--delay' => 0])
            ->expectsOutputToContain('Daily API budget')
            ->assertExitCode(0);
    }

    public function test_falls_back_to_search_when_no_passage_id(): void
    {
        ScripturePassage::factory()->stale()->create([
            'api_passage_id' => null,
            'normalized_reference' => 'John 3:16',
        ]);

        $apiResult = new ApiBiblePassageResult(
            passageId: 'JHN.3.16',
            displayReference: 'John 3:16',
            htmlContent: '<p>Refreshed via search.</p>',
            copyright: 'NIV',
            fumsToken: 'tok2',
        );

        $client = $this->createMock(ApiBibleClient::class);
        $client->expects($this->never())->method('fetchPassageById');
        $client->expects($this->once())->method('searchPassage')->with('John 3:16')->willReturn($apiResult);
        $this->app->instance(ApiBibleClient::class, $client);

        $this->artisan('scripture:refresh-passages', ['--delay' => 0])
            ->expectsOutputToContain('Updated: 1')
            ->assertExitCode(0);
    }
}
