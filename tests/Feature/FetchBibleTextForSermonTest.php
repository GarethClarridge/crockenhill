<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\ApiBiblePassageResult;
use App\Jobs\FetchBibleTextForSermon;
use App\Models\ScripturePassage;
use App\Models\Sermon;
use App\Services\ApiBibleClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class FetchBibleTextForSermonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.api_bible.enabled', true);
        Config::set('services.api_bible.default_bible_id', 'de4e12af7f28f599-02');
        Config::set('services.api_bible.refresh_after_days', 28);
    }

    public function test_skips_when_feature_disabled(): void
    {
        Config::set('services.api_bible.enabled', false);

        $sermon = Sermon::factory()->create(['reference' => 'John 3:16']);

        $client = $this->createMock(ApiBibleClient::class);
        $client->expects($this->never())->method('searchPassage');

        $this->app->instance(ApiBibleClient::class, $client);

        FetchBibleTextForSermon::dispatchSync($sermon);

        $this->assertNull($sermon->fresh()->scripture_passage_id);
    }

    public function test_skips_when_no_reference(): void
    {
        $sermon = Sermon::factory()->create(['reference' => null]);

        $client = $this->createMock(ApiBibleClient::class);
        $client->expects($this->never())->method('searchPassage');

        $this->app->instance(ApiBibleClient::class, $client);

        FetchBibleTextForSermon::dispatchSync($sermon);

        $this->assertNull($sermon->fresh()->scripture_passage_id);
    }

    public function test_skips_unparseable_reference(): void
    {
        $sermon = Sermon::factory()->create(['reference' => 'xyzzy 99:99']);

        $client = $this->createMock(ApiBibleClient::class);
        $client->expects($this->never())->method('searchPassage');

        $this->app->instance(ApiBibleClient::class, $client);

        FetchBibleTextForSermon::dispatchSync($sermon);

        $this->assertNull($sermon->fresh()->scripture_passage_id);
    }

    public function test_resolves_and_links_passage_on_success(): void
    {
        $sermon = Sermon::factory()->create(['reference' => 'John 3:16']);

        $apiResult = new ApiBiblePassageResult(
            passageId: 'JHN.3.16',
            displayReference: 'John 3:16',
            htmlContent: '<p><sup>16</sup>For God so loved the world.</p>',
            copyright: 'NIV Copyright',
            fumsToken: 'test-fums-token',
        );

        $client = $this->createMock(ApiBibleClient::class);
        $client->expects($this->once())->method('searchPassage')->willReturn($apiResult);

        $this->app->instance(ApiBibleClient::class, $client);

        FetchBibleTextForSermon::dispatchSync($sermon);

        $sermon->refresh();
        $this->assertNotNull($sermon->scripture_passage_id);

        $passage = ScripturePassage::find($sermon->scripture_passage_id);
        $this->assertNotNull($passage);
        $this->assertSame('JHN.3.16', $passage->api_passage_id);
        $this->assertSame('test-fums-token', $passage->fums_token);
        $this->assertStringContainsString('For God so loved', $passage->html_content);
    }

    public function test_reuses_cached_passage_when_not_stale(): void
    {
        $sermon = Sermon::factory()->create(['reference' => 'John 3:16']);

        $existing = ScripturePassage::factory()->create([
            'bible_id' => 'de4e12af7f28f599-02',
            'normalized_reference' => 'John 3:16',
            'fetched_at' => now()->subDays(5),
        ]);

        $client = $this->createMock(ApiBibleClient::class);
        $client->expects($this->never())->method('searchPassage');
        $client->expects($this->never())->method('fetchPassageById');

        $this->app->instance(ApiBibleClient::class, $client);

        FetchBibleTextForSermon::dispatchSync($sermon);

        $sermon->refresh();
        $this->assertSame($existing->id, $sermon->scripture_passage_id);
    }

    public function test_handles_api_not_found_gracefully(): void
    {
        $sermon = Sermon::factory()->create(['reference' => 'John 3:16']);

        $client = $this->createMock(ApiBibleClient::class);
        $client->expects($this->once())->method('searchPassage')->willReturn(null);

        $this->app->instance(ApiBibleClient::class, $client);

        FetchBibleTextForSermon::dispatchSync($sermon);

        $this->assertNull($sermon->fresh()->scripture_passage_id);
    }

    public function test_rethrows_on_rate_limit_or_server_error_so_job_retries(): void
    {
        $sermon = Sermon::factory()->create(['reference' => 'John 3:16']);

        $client = $this->createMock(ApiBibleClient::class);
        $client->expects($this->once())
            ->method('searchPassage')
            ->willThrowException(new \RuntimeException('api.bible search failed with status 429 after retries'));

        $this->app->instance(ApiBibleClient::class, $client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/429/');

        FetchBibleTextForSermon::dispatchSync($sermon);
    }

    public function test_refreshes_stale_passage_using_passage_id(): void
    {
        $sermon = Sermon::factory()->create(['reference' => 'John 3:16']);

        $stalePassage = ScripturePassage::factory()->stale()->create([
            'bible_id' => 'de4e12af7f28f599-02',
            'normalized_reference' => 'John 3:16',
            'api_passage_id' => 'JHN.3.16',
        ]);

        $apiResult = new ApiBiblePassageResult(
            passageId: 'JHN.3.16',
            displayReference: 'John 3:16',
            htmlContent: '<p>Fresh content.</p>',
            copyright: 'NIV',
            fumsToken: 'new-fums-token',
        );

        $client = $this->createMock(ApiBibleClient::class);
        $client->expects($this->never())->method('searchPassage');
        $client->expects($this->once())->method('fetchPassageById')->with('JHN.3.16')->willReturn($apiResult);

        $this->app->instance(ApiBibleClient::class, $client);

        FetchBibleTextForSermon::dispatchSync($sermon);

        $stalePassage->refresh();
        $this->assertSame('new-fums-token', $stalePassage->fums_token);
        $sermon->refresh();
        $this->assertSame($stalePassage->id, $sermon->scripture_passage_id);
    }
}
