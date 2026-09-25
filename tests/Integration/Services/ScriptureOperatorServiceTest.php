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
        $this->assertSame(0, $result['summary']['unparseable'], 'An unparseable reference is left out of the batch, not attempted.');
        $this->assertSame([$sermon->id], $result['sermons']->pluck('id')->all());
        $this->assertSame(0, $result['summary']['failed']);
        $this->assertNotNull($sermon->fresh()->scripture_passage_id);
    }

    /**
     * Sermons 908–915 (2026-09-02/03) queued enrichment that never ran, and no backfill
     * reached them: hundreds of older unlinked sermons came first in id order.
     */
    public function test_enrichment_reaches_the_newest_unlinked_sermons_first(): void
    {
        $older = Sermon::factory()->create(['reference' => 'xyzzy 99:99', 'scripture_passage_id' => null]);
        $newest = Sermon::factory()->create(['reference' => 'John 3:16', 'scripture_passage_id' => null]);

        $client = $this->mockClientWithBudget();
        $this->mockResolver(['John 3:16' => 'John 3:16', 'xyzzy 99:99' => null]);
        $this->mockSanitizer();
        $client->method('searchPassage')->willReturn(new ApiBiblePassageResult(
            passageId: 'JHN.3.16',
            displayReference: 'John 3:16',
            htmlContent: '<p>For God so loved the world.</p>',
            copyright: 'NIV',
            fumsToken: 'tok',
        ));
        $this->app->instance(ApiBibleClient::class, $client);

        $result = app(ScriptureOperatorService::class)->runEnrichment(limit: 1, delayMs: 0);

        $this->assertSame([$newest->id], $result['sermons']->pluck('id')->all());
        $this->assertNotNull($newest->fresh()->scripture_passage_id);
        $this->assertNull($older->fresh()->scripture_passage_id);
    }

    /**
     * A reference that cannot parse never reaches the API, so it must not take the batch's
     * slot either: newest-first would otherwise starve every older sermon behind it.
     */
    public function test_an_unparseable_reference_takes_no_slot_in_a_limited_batch(): void
    {
        $older = Sermon::factory()->create(['reference' => 'John 3:16', 'scripture_passage_id' => null]);
        Sermon::factory()->create(['reference' => 'The Christmas story', 'scripture_passage_id' => null]);

        $client = $this->mockClientWithBudget();
        $this->mockResolver(['John 3:16' => 'John 3:16']);
        $this->mockSanitizer();
        $client->method('searchPassage')->willReturn($this->johnThreeSixteen());
        $this->app->instance(ApiBibleClient::class, $client);

        $result = app(ScriptureOperatorService::class)->runEnrichment(limit: 1, delayMs: 0);

        $this->assertSame([$older->id], $result['sermons']->pluck('id')->all());
        $this->assertNotNull($older->fresh()->scripture_passage_id);
    }

    /**
     * api.bible's miss is terminal for a reference, so it is remembered: retrying it every
     * run would hold the batch's newest slots for ever.
     */
    public function test_a_reference_api_bible_missed_is_not_retried_by_the_next_batch(): void
    {
        $older = Sermon::factory()->create(['reference' => 'John 3:16', 'scripture_passage_id' => null]);
        $missed = Sermon::factory()->create(['reference' => 'Obadiah 1:30', 'scripture_passage_id' => null]);

        $client = $this->mockClientWithBudget();
        $this->mockResolver(['John 3:16' => 'John 3:16', 'Obadiah 1:30' => 'Obadiah 1:30']);
        $this->mockSanitizer();
        $client->expects($this->exactly(2))->method('searchPassage')
            ->willReturnCallback(fn (string $reference): ?ApiBiblePassageResult => $reference === 'John 3:16' ? $this->johnThreeSixteen() : null);
        $this->app->instance(ApiBibleClient::class, $client);

        $first = app(ScriptureOperatorService::class)->runEnrichment(limit: 1, delayMs: 0);
        $second = app(ScriptureOperatorService::class)->runEnrichment(limit: 1, delayMs: 0);

        $this->assertSame([$missed->id], $first['sermons']->pluck('id')->all());
        $this->assertSame(1, $first['summary']['not_found']);
        $this->assertSame([$older->id], $second['sermons']->pluck('id')->all());
        $this->assertNotNull($older->fresh()->scripture_passage_id);
    }

    public function test_the_candidate_count_honours_its_limit_and_skips_what_the_batch_would(): void
    {
        Sermon::factory()->count(3)->create(['reference' => 'John 3:16', 'scripture_passage_id' => null]);
        Sermon::factory()->create(['reference' => 'The Christmas story', 'scripture_passage_id' => null]);
        $this->mockResolver(['John 3:16' => 'John 3:16']);

        $service = app(ScriptureOperatorService::class);

        $this->assertSame(2, $service->countEnrichmentCandidates(2));
        $this->assertSame(3, $service->countEnrichmentCandidates(10));
    }

    private function johnThreeSixteen(): ApiBiblePassageResult
    {
        return new ApiBiblePassageResult(
            passageId: 'JHN.3.16',
            displayReference: 'John 3:16',
            htmlContent: '<p>For God so loved the world.</p>',
            copyright: 'NIV',
            fumsToken: 'tok',
        );
    }

    public function test_enrichment_stores_normalized_reference_when_api_display_span_differs(): void
    {
        // Real resolver: the guard must compare verse spans, not strings.
        $sermon = Sermon::factory()->create([
            'reference' => 'Joshua 4:1-5:1',
            'scripture_passage_id' => null,
        ]);

        $client = $this->mockClientWithBudget();
        $this->mockSanitizer();
        // api.bible renders JOS.4.1-JOS.5.1 as "Joshua 4:1-51" (chapter colon lost).
        $client->method('searchPassage')
            ->willReturn(new ApiBiblePassageResult(
                passageId: 'JOS.4.1-JOS.5.1',
                displayReference: 'Joshua 4:1-51',
                htmlContent: '<p>When the whole nation had finished crossing.</p>',
                copyright: 'NIV',
                fumsToken: 'tok',
            ));
        $this->app->instance(ApiBibleClient::class, $client);

        app(ScriptureOperatorService::class)->runEnrichment(delayMs: 0);

        $passage = ScripturePassage::query()->firstOrFail();
        $this->assertSame('Joshua 4:1-5:1', $passage->display_reference);
        $this->assertSame('Joshua 4:1-5:1', $sermon->fresh()->reference);
    }

    public function test_refresh_repairs_a_mangled_display_reference_and_resyncs_linked_sermons(): void
    {
        $passage = ScripturePassage::factory()->stale()->create([
            'api_passage_id' => 'JOS.4.1-JOS.5.1',
            'normalized_reference' => 'Joshua 4:1-5:1',
            'display_reference' => 'Joshua 4:1-51',
        ]);
        $sermon = Sermon::factory()->create([
            'reference' => 'Joshua 4:1-51',
            'scripture_passage_id' => $passage->id,
        ]);

        $client = $this->mockClientWithBudget();
        $this->mockSanitizer();
        $client->method('fetchPassageById')
            ->willReturn(new ApiBiblePassageResult(
                passageId: 'JOS.4.1-JOS.5.1',
                displayReference: 'Joshua 4:1-51',
                htmlContent: '<p>Fresh content.</p>',
                copyright: 'NIV',
                fumsToken: 'tok',
            ));
        $this->app->instance(ApiBibleClient::class, $client);

        app(ScriptureOperatorService::class)->runRefresh(delayMs: 0);

        $this->assertSame('Joshua 4:1-5:1', $passage->fresh()->display_reference);
        $this->assertSame('Joshua 4:1-5:1', $sermon->fresh()->reference);
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
