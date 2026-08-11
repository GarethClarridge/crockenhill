<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Data\ApiBiblePassageResult;
use App\Models\ScripturePassage;
use App\Services\HistoricMedia\HistoricProcessingResultBundle;
use App\Services\Scripture\ApiBibleClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

/**
 * Decision D3's pre-apply enrichment pass. The read-only default is the gate the
 * runbook runs before the window; --apply is the pass itself.
 */
class EnrichHistoricScripturePassagesCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $bundlePath;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.api_bible.enabled', true);
        Config::set('services.api_bible.default_bible_id', 'bible-a');
        Config::set('services.api_bible.refresh_after_days', 28);
        Config::set('services.api_bible.daily_budget', 5000);
        Cache::flush();

        $path = tempnam(sys_get_temp_dir(), 'crockenhill-bundle-');
        self::assertIsString($path);
        $this->bundlePath = $path;
    }

    protected function tearDown(): void
    {
        if (is_file($this->bundlePath)) {
            unlink($this->bundlePath);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_reports_and_fails_when_the_destination_cannot_satisfy_the_bundle(): void
    {
        $this->writeBundle(['John 3:16', 'Acts 2:1']);

        $this->artisan('historic-import:enrich-scripture-passages', ['media-bundle' => $this->bundlePath])
            ->expectsOutputToContain('Required passage identities: 2')
            ->expectsOutputToContain('Missing:                     2')
            ->expectsOutputToContain('bible-a|Acts 2:1')
            ->expectsOutputToContain('bible-a|John 3:16')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_passes_when_every_identity_is_already_present(): void
    {
        ScripturePassage::factory()->create(['bible_id' => 'bible-a', 'normalized_reference' => 'John 3:16']);
        $this->writeBundle(['John 3:16']);

        $this->artisan('historic-import:enrich-scripture-passages', ['media-bundle' => $this->bundlePath])
            ->expectsOutputToContain('The destination can satisfy every Scripture Passage this bundle requires.')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_fetches_only_the_identities_the_bundle_carries(): void
    {
        ScripturePassage::factory()->create(['bible_id' => 'bible-a', 'normalized_reference' => 'John 3:16']);
        $this->writeBundle(['John 3:16', 'Acts 2:1']);

        $client = $this->mockClientWithBudget();
        $client->expects($this->once())
            ->method('searchPassage')
            ->with('Acts 2:1')
            ->willReturn(new ApiBiblePassageResult(
                passageId: 'ACT.2.1',
                displayReference: 'Acts 2:1',
                htmlContent: '<p>When the day of Pentecost came.</p>',
                copyright: 'NIV',
                fumsToken: 'tok',
            ));
        $this->app->instance(ApiBibleClient::class, $client);

        $this->artisan('historic-import:enrich-scripture-passages', [
            'media-bundle' => $this->bundlePath,
            '--apply' => true,
            '--delay' => 0,
        ])
            ->expectsOutputToContain('Every required Scripture Passage is now present.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('scripture_passages', [
            'bible_id' => 'bible-a',
            'normalized_reference' => 'Acts 2:1',
        ]);
    }

    #[Test]
    public function it_fails_when_an_identity_cannot_be_resolved(): void
    {
        $this->writeBundle(['Hesitations 4:2']);

        $client = $this->mockClientWithBudget();
        $client->expects($this->once())->method('searchPassage')->willReturn(null);
        $this->app->instance(ApiBibleClient::class, $client);

        $this->artisan('historic-import:enrich-scripture-passages', [
            'media-bundle' => $this->bundlePath,
            '--apply' => true,
            '--delay' => 0,
        ])
            ->expectsOutputToContain('bible-a|Hesitations 4:2 (not_found)')
            ->assertExitCode(1);

        $this->assertDatabaseCount('scripture_passages', 0);
    }

    /**
     * A disabled client resolves nothing, so an --apply run that "succeeded"
     * against it would report every identity as an unremarkable not_found and
     * leave the operator believing the pass had run.
     */
    #[Test]
    public function it_refuses_to_apply_while_api_bible_is_disabled(): void
    {
        Config::set('services.api_bible.enabled', false);
        $this->writeBundle(['John 3:16']);

        $client = $this->mockClientWithBudget();
        $client->expects($this->never())->method('searchPassage');
        $this->app->instance(ApiBibleClient::class, $client);

        $this->artisan('historic-import:enrich-scripture-passages', [
            'media-bundle' => $this->bundlePath,
            '--apply' => true,
        ])
            ->expectsOutputToContain('api.bible is disabled')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_fails_on_a_bundle_file_it_cannot_read(): void
    {
        $this->artisan('historic-import:enrich-scripture-passages', [
            'media-bundle' => '/nonexistent/bundle.json',
        ])
            ->expectsOutputToContain('Bundle file is missing')
            ->assertExitCode(1);
    }

    /** @param list<string> $references */
    private function writeBundle(array $references): void
    {
        $bundle = app(HistoricProcessingResultBundle::class)->make(
            str_repeat('6', 64),
            ['pipeline_version' => 1],
            [[
                'date' => '2026-08-02',
                'service' => 'morning',
                'source_manifest_hash' => str_repeat('1', 64),
                'evidence_set_hash' => str_repeat('2', 64),
                'pre_review_hash' => str_repeat('3', 64),
                'media_graph' => [
                    'processing_key' => 'historic-run',
                    'publications' => array_map(static fn (string $reference): array => [
                        'slug' => 'sermon-'.md5($reference),
                        'scripture_passage' => [
                            'bible_id' => 'bible-a',
                            'normalized_reference' => $reference,
                        ],
                        'scripture_passage_outcome' => ['status' => 'linked'],
                    ], $references),
                ],
                'livestream_source_revision' => [],
                'assets' => [],
            ]],
        );

        file_put_contents($this->bundlePath, json_encode($bundle, JSON_THROW_ON_ERROR));
    }

    /** @return MockObject&ApiBibleClient */
    private function mockClientWithBudget(): MockObject
    {
        $client = $this->createMock(ApiBibleClient::class);
        $client->method('hasDailyBudget')->willReturn(true);

        return $client;
    }
}
