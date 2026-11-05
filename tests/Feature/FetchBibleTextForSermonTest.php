<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\ApiBiblePassageResult;
use App\Jobs\FetchBibleTextForSermon;
use App\Models\ScripturePassage;
use App\Models\Sermon;
use App\Services\Scripture\ApiBibleClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\FailOnException;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
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

    /**
     * The 11-02 round's refresh discarded 33 valid passages because api.bible sent FUMS tokens
     * longer than 255 characters. The token is the provider's usage receipt and is sent back as
     * written, so it is stored whole, never truncated.
     */
    #[Test]
    public function a_provider_token_longer_than_255_characters_is_stored_whole(): void
    {
        $sermon = Sermon::factory()->create([
            'reference' => 'John 3:16',
            'scripture_passage_id' => null,
        ]);
        $token = str_repeat('x', 1200);

        $client = $this->createMock(ApiBibleClient::class);
        $client->expects($this->once())->method('searchPassage')->willReturn(new ApiBiblePassageResult(
            passageId: 'JHN.3.16',
            displayReference: 'John 3:16',
            htmlContent: '<p>For God so loved the world.</p>',
            copyright: 'NIV Copyright',
            fumsToken: $token,
        ));
        $this->app->instance(ApiBibleClient::class, $client);

        FetchBibleTextForSermon::dispatchSync($sermon);

        $passage = ScripturePassage::query()->where('normalized_reference', 'John 3:16')->sole();
        $this->assertSame($passage->id, $sermon->fresh()->scripture_passage_id);
        $this->assertSame($token, $passage->fums_token);
    }

    /**
     * A refresh that could not store its passage failed: the job says so, so the queue retries it
     * and records it, instead of returning normally over discarded passage data.
     */
    #[Test]
    public function a_passage_that_cannot_be_stored_fails_the_job(): void
    {
        $sermon = Sermon::factory()->create([
            'reference' => 'John 3:16',
            'scripture_passage_id' => null,
        ]);

        $client = $this->createStub(ApiBibleClient::class);
        $client->method('searchPassage')->willReturn(new ApiBiblePassageResult(
            passageId: 'JHN.3.16',
            displayReference: 'John 3:16',
            htmlContent: '<script>nothing a page may show</script>',
            copyright: 'NIV Copyright',
            fumsToken: null,
        ));
        $this->app->instance(ApiBibleClient::class, $client);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('could not be stored');

        FetchBibleTextForSermon::dispatchSync($sermon);
    }

    /** A reference with no passage to find is a terminal outcome, not a failure. */
    #[Test]
    public function a_reference_the_provider_cannot_find_does_not_fail_the_job(): void
    {
        $sermon = Sermon::factory()->create([
            'reference' => 'John 3:16',
            'scripture_passage_id' => null,
        ]);

        $client = $this->createStub(ApiBibleClient::class);
        $client->method('searchPassage')->willReturn(null);
        $this->app->instance(ApiBibleClient::class, $client);

        FetchBibleTextForSermon::dispatchSync($sermon);

        $this->assertNull($sermon->fresh()->scripture_passage_id);
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
            ->willThrowException(new RuntimeException('api.bible search failed with status 429 after retries'));

        $this->app->instance(ApiBibleClient::class, $client);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/429/');

        FetchBibleTextForSermon::dispatchSync($sermon);
    }

    public function test_budget_exhausted_exception_triggers_fail_on_exception_middleware(): void
    {
        $sermon = Sermon::factory()->create(['reference' => 'John 3:16']);
        $job = new FetchBibleTextForSermon($sermon);

        $middleware = $job->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(FailOnException::class, $middleware[0]);
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
