<?php

declare(strict_types=1);

namespace Tests\Integration\Services\HistoricMedia;

use App\Data\HistoricProcessingResultImportPlan;
use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\SermonContentType;
use App\Enums\SermonService;
use App\Enums\SermonSourceType;
use App\Enums\SermonVideoQualityStatus;
use App\Enums\SermonVideoVisibilityOverride;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Services\HistoricMedia\HistoricMediaGraphPersister;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * B18: the graph persister used to create every publication unconditionally, so a
 * historic bundle re-describing a sermon production already holds either duplicated
 * it or collided on the slug unique key. Convergence must reuse a strong identity,
 * enrich only what production is missing, and refuse anything else.
 */
class HistoricPublicationConvergenceTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE_HASH = 'aa11bb22cc33dd44ee55ff6600778899aa11bb22cc33dd44ee55ff6600778899';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('historic_staging');
        Storage::fake('local');
        config()->set('media-processing.storage.historic_staging_disk', 'historic_staging');
        config()->set('media-processing.storage.historic_quarantine_disk', 'local');
        config()->set('media-processing.storage.sermon_disk', 'local');
    }

    #[Test]
    public function existing_publications_are_reused_enriched_or_blocked_never_blindly_created(): void
    {
        $existing = $this->productionSermon([
            'summary' => 'Production summary a human wrote',
            'duration' => null,
            'series' => null,
        ]);
        $this->previousRunFor($existing);

        $plan = $this->plan('00000000-0000-0000-0000-0000000000b1', [
            $this->publication([
                'summary' => null,
                'duration' => 3600.0,
                'series' => 'Historic series',
            ]),
        ]);

        $result = app(HistoricMediaGraphPersister::class)->persist($plan);
        $converged = $existing->fresh();

        /** Reused: the strong source-hash identity binds the new run to the same row. */
        $this->assertSame(1, Sermon::query()->count());
        $this->assertSame($existing->id, $result['processing_log']->sermon_id);

        /** Enriched: only the fields production was missing are filled. */
        $this->assertSame(3600.0, $converged->duration);
        $this->assertSame('Historic series', $converged->series);

        /** Preserved: the richer production value wins over the bundle's null. */
        $this->assertSame('Production summary a human wrote', $converged->summary);
    }

    #[Test]
    public function it_blocks_a_publication_that_conflicts_with_the_production_record(): void
    {
        $existing = $this->productionSermon(['title' => 'The title production already holds']);
        $this->previousRunFor($existing);

        $plan = $this->plan('00000000-0000-0000-0000-0000000000b2', [
            $this->publication(['title' => 'The title the bundle carries']),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Historic publication title conflicts with the richer production record.');

        app(HistoricMediaGraphPersister::class)->persist($plan);
    }

    #[Test]
    public function it_refuses_to_create_a_publication_whose_slug_belongs_to_another_sermon(): void
    {
        /** No strong identity: an unrelated sermon simply owns the slug already. */
        Sermon::factory()->create(['slug' => 'convergence-main-sermon']);

        $plan = $this->plan('00000000-0000-0000-0000-0000000000b3', [$this->publication()]);

        $exception = null;

        try {
            app(HistoricMediaGraphPersister::class)->persist($plan);
        } catch (RuntimeException $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertSame(
            'Historic publication slug already belongs to a different sermon.',
            $exception->getMessage(),
        );
        $this->assertSame(1, Sermon::query()->count());
    }

    /**
     * A production sermon that predates thumbnail generation carries no
     * `thumbnail_metadata` at all. The bundle's metadata has to be merged in before
     * the candidate asset roles can be remapped onto it.
     */
    #[Test]
    public function it_merges_bundle_thumbnail_metadata_into_an_existing_publication(): void
    {
        Storage::disk('historic_staging')->put('staged/candidate.webp', 'candidate plain');

        $existing = $this->productionSermon(['thumbnail_metadata' => null]);
        $this->previousRunFor($existing);

        $processingId = '00000000-0000-0000-0000-0000000000b4';
        $plan = $this->plan(
            $processingId,
            [$this->publication([
                'thumbnail_metadata' => [
                    'thumbnail_candidates' => [[
                        'id' => 'candidate-1',
                        'timestamp' => 12.0,
                        'score' => 0.9,
                        'plain_path' => 'staged/candidate.webp',
                    ]],
                ],
            ])],
            [[
                'path' => 'staged/candidate.webp',
                'size' => 15,
                'sha256' => hash('sha256', 'candidate plain'),
                'kind' => 'thumbnail',
                'roles' => ["publication:{$processingId}:main:sermon:thumbnail_candidate:0:plain_path"],
            ]],
        );

        app(HistoricMediaGraphPersister::class)->persist($plan);
        $metadata = $existing->fresh()->thumbnail_metadata;

        $this->assertSame(
            "sermons/{$existing->id}/thumbnail-candidate-0-plain.webp",
            $metadata?->thumbnailCandidates[0]['plain_path'],
        );
        Storage::disk('local')->assertExists("sermons/{$existing->id}/thumbnail-candidate-0-plain.webp");
    }

    /**
     * The inventory emits the run's own sermon and every section's published sermon.
     * Two distinct sermon-typed publications in one bundle must stay two rows: the
     * first one created must not become the strong identity for the second.
     */
    #[Test]
    public function it_keeps_two_publications_of_one_content_type_distinct(): void
    {
        $plan = $this->plan('00000000-0000-0000-0000-0000000000b5', [
            $this->publication(),
            $this->publication([
                'section_key' => 'convergence-section',
                'slug' => 'convergence-section-sermon',
                'title' => 'Section sermon',
            ]),
        ]);

        app(HistoricMediaGraphPersister::class)->persist($plan);

        $this->assertSame(2, Sermon::query()->count());
        $this->assertSame(
            'Main sermon',
            Sermon::query()->where('slug', 'convergence-main-sermon')->sole()->title,
        );
        $this->assertSame(
            'Section sermon',
            Sermon::query()->where('slug', 'convergence-section-sermon')->sole()->title,
        );
    }

    /**
     * A key the bundle never carries is absent data, not an assertion that the value
     * is false. Defaulting it and then comparing would block every import against a
     * production record whose flags a human had turned on.
     */
    #[Test]
    public function it_preserves_a_production_flag_the_bundle_does_not_carry(): void
    {
        $existing = $this->productionSermon([
            'show_summary' => true,
            'show_points' => true,
            'filetype' => 'mp4',
        ]);
        $this->previousRunFor($existing);

        $publication = $this->publication();
        unset($publication['show_summary'], $publication['show_points'], $publication['filetype']);

        $plan = $this->plan('00000000-0000-0000-0000-0000000000b6', [$publication]);

        app(HistoricMediaGraphPersister::class)->persist($plan);
        $converged = $existing->fresh();

        $this->assertTrue($converged->show_summary);
        $this->assertTrue($converged->show_points);
        $this->assertSame('mp4', $converged->filetype);
    }

    /** The single preacher every fixture in this file shares, so a name mismatch never masks a convergence failure. */
    private function preacher(): Preacher
    {
        return Preacher::query()->firstOrCreate(
            ['slug' => 'convergence-test-preacher'],
            ['name' => 'Convergence Test Preacher', 'is_active' => true],
        );
    }

    /** @param array<string, mixed> $overrides */
    private function productionSermon(array $overrides = []): Sermon
    {
        $preacher = $this->preacher();

        return Sermon::factory()->create([
            'preacher' => $preacher->name,
            'preacher_id' => $preacher->id,
            'date' => '2026-08-02',
            'service' => SermonService::Morning,
            'content_type' => SermonContentType::Sermon,
            'slug' => 'convergence-main-sermon',
            'title' => 'Main sermon',
            'filetype' => 'mp3',
            'reference' => null,
            'series' => null,
            'summary' => null,
            'points' => null,
            'show_summary' => false,
            'show_points' => false,
            'duration' => null,
            'source_type' => SermonSourceType::Livestream,
            'video_quality_status' => SermonVideoQualityStatus::Unassessed,
            'video_visibility_override' => SermonVideoVisibilityOverride::Default,
            'thumbnail_metadata' => null,
            ...$overrides,
        ]);
    }

    /**
     * A completed run over the same source media, which is the strong publication
     * identity the persister converges on when the processing id itself is new.
     */
    private function previousRunFor(Sermon $sermon): MediaProcessingLog
    {
        return MediaProcessingLog::factory()->livestream()->create([
            'processing_id' => 'convergence-previous-run',
            'file_hash' => self::SOURCE_HASH,
            'sermon_id' => $sermon->id,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $publications
     * @param  list<array<string, mixed>>  $assets
     */
    private function plan(
        string $processingId,
        array $publications,
        array $assets = [],
    ): HistoricProcessingResultImportPlan {
        $date = '2026-08-02';

        return new HistoricProcessingResultImportPlan(
            classification: 'create',
            reason: 'test',
            planHash: str_repeat('a', 64),
            bundleHash: str_repeat('b', 64),
            service: [
                'media_graph' => [
                    'processing_key' => $processingId,
                    'run' => [
                        'processing_id' => $processingId,
                        'processing_type' => MediaType::Livestream->value,
                        'status' => ProcessingStatus::Completed->value,
                        'current_step' => 'completed',
                        'original_filename' => 'convergence-test.mp4',
                        'file_hash' => self::SOURCE_HASH,
                        'file_size' => 100,
                        'duration' => 3600.0,
                        'extracted_date' => $date,
                        'extracted_service' => SermonService::Morning->value,
                        'sermon_start_time' => null,
                        'sermon_end_time' => null,
                        'threshold_method' => null,
                        'adaptive_threshold' => null,
                        'rms_stats' => null,
                        'started_at' => "{$date}T10:00:00+00:00",
                        'completed_at' => "{$date}T11:00:00+00:00",
                        'is_degraded_completion' => false,
                    ],
                    'metadata' => [],
                    'logical_hash' => str_repeat('d', 64),
                    'steps' => [],
                    'segments' => [],
                    'sections' => [],
                    'publications' => $publications,
                    'song_videos' => [],
                ],
            ],
            assets: $assets,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function publication(array $overrides = []): array
    {
        return [
            'section_key' => null,
            'content_type' => SermonContentType::Sermon->value,
            'date' => '2026-08-02',
            'service' => SermonService::Morning->value,
            'slug' => 'convergence-main-sermon',
            'filetype' => 'mp3',
            'title' => 'Main sermon',
            'reference' => null,
            'series' => null,
            'summary' => null,
            'meta_description' => null,
            'points' => null,
            'show_summary' => false,
            'show_points' => false,
            'duration' => 3600.0,
            'segment_start_time' => null,
            'segment_end_time' => null,
            'preacher_source' => null,
            'preacher_confidence' => null,
            'needs_preacher_review' => false,
            'source_type' => SermonSourceType::Livestream->value,
            'video_quality_status' => SermonVideoQualityStatus::Unassessed->value,
            'video_quality_reason' => null,
            'video_visibility_override' => SermonVideoVisibilityOverride::Default->value,
            'video_quality_assessed_at' => null,
            'thumbnail_generated_at' => null,
            'thumbnail_metadata' => null,
            'preacher' => [
                'name' => 'Convergence Test Preacher',
                'slug' => 'convergence-test-preacher',
                'aliases' => [],
            ],
            'scripture_filters' => [],
            /** HIR3: an absent passage still has to name how it was settled. */
            'scripture_passage' => null,
            'scripture_passage_outcome' => ['status' => 'approved_absent', 'reason' => 'source_has_no_passage'],
            ...$overrides,
        ];
    }
}
