<?php

declare(strict_types=1);

namespace Tests\Integration\Services\HistoricMedia;

use App\Data\HistoricProcessingResultImportPlan;
use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\SermonService;
use App\Enums\SermonSourceType;
use App\Enums\SermonVideoQualityStatus;
use App\Enums\SermonVideoVisibilityOverride;
use App\Enums\TalkType;
use App\Exceptions\SeedResidueException;
use App\Models\MediaProcessingLog;
use App\Models\PreacherAlias;
use App\Models\Sermon;
use App\Services\HistoricMedia\HistoricMediaGraphPersister;
use App\Support\SeedSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HistoricMediaGraphPersisterSeedRefusalTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_refuses_to_create_a_sermon_carrying_seeded_values(): void
    {
        $publication = $this->publication();
        $publication['date'] = '2024-12-08';
        $publication['title'] = 'Walking in the Light';
        $publication['slug'] = 'walking-in-the-light';

        $this->assertRefused([$publication], 'walking-in-the-light');

        $this->assertSame(0, Sermon::query()->count());
        $this->assertSame(0, MediaProcessingLog::query()->count());
    }

    #[Test]
    public function it_refuses_to_create_the_guest_speaker_alias(): void
    {
        $publication = $this->publication();
        $publication['preacher'] = [
            'name' => 'Visiting Speaker',
            'slug' => 'visiting-speaker',
            'aliases' => ['visiting speaker', SeedSignature::ALIAS],
        ];

        $this->assertRefused([$publication], "preacher alias 'guest speaker'");

        $this->assertSame(0, PreacherAlias::query()->where('alias', SeedSignature::ALIAS)->count());
        $this->assertSame(0, Sermon::query()->count());
    }

    #[Test]
    public function it_refuses_a_seeded_only_preacher(): void
    {
        $publication = $this->publication();
        $publication['preacher'] = ['name' => 'Paul Wilson', 'slug' => 'paul-wilson', 'aliases' => []];

        $this->assertRefused([$publication], 'seeded preacher');

        $this->assertSame(0, Sermon::query()->count());
    }

    #[Test]
    public function it_refuses_the_seeded_sample_run(): void
    {
        $this->expectException(SeedResidueException::class);
        $this->expectExceptionMessage(SeedSignature::PROCESSING_ID);

        app(HistoricMediaGraphPersister::class)->persist($this->planWithGraph(SeedSignature::PROCESSING_ID, [$this->publication()]));
    }

    #[Test]
    public function it_still_creates_real_values_on_a_seeded_date(): void
    {
        $publication = $this->publication();
        $publication['date'] = '2024-12-08';
        $publication['title'] = 'The lost older son';
        $publication['slug'] = 'the-lost-older-son';
        $publication['preacher']['aliases'] = ['visiting speaker'];

        app(HistoricMediaGraphPersister::class)->persist($this->planWithGraph('00000000-0000-0000-0000-000000000031', [$publication]));

        $this->assertDatabaseHas('sermons', ['slug' => 'the-lost-older-son']);
    }

    /** @param list<array<string, mixed>> $publications */
    private function assertRefused(array $publications, string $message): void
    {
        try {
            app(HistoricMediaGraphPersister::class)->persist($this->planWithGraph('00000000-0000-0000-0000-000000000030', $publications));
            $this->fail('A seeded value was persisted.');
        } catch (SeedResidueException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    /**
     * @param  list<array<string, mixed>>  $publications
     * @param  list<array<string, mixed>>  $sections
     * @param  list<array<string, mixed>>  $assets
     */
    private function planWithGraph(
        string $processingId,
        array $publications = [],
        array $sections = [],
        array $assets = [],
        array $metadata = [],
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
                        'original_filename' => 'persister-test.mp4',
                        'file_hash' => str_repeat('c', 64),
                        'file_size' => 100,
                        'duration' => 60.0,
                        'extracted_date' => $date,
                        'extracted_service' => SermonService::Morning->value,
                        'audio_file_path' => null,
                        'video_file_path' => null,
                        'transcript_file_path' => null,
                        'rms_log_path' => null,
                        'sermon_start_time' => null,
                        'sermon_end_time' => null,
                        'threshold_method' => null,
                        'adaptive_threshold' => null,
                        'rms_stats' => null,
                        'started_at' => "{$date}T10:00:00+00:00",
                        'completed_at' => "{$date}T11:00:00+00:00",
                        'is_degraded_completion' => false,
                    ],
                    'metadata' => $metadata,
                    'logical_hash' => str_repeat('d', 64),
                    'steps' => [],
                    'segments' => [],
                    'sections' => $sections,
                    'publications' => $publications,
                    'song_videos' => [],
                ],
            ],
            assets: $assets,
        );
    }

    /**
     * @param  list<array{bible_book: string, bible_chapter: int}>  $scriptureFilters
     * @return array<string, mixed>
     */
    private function publication(
        ?string $sectionKey = null,
        ?string $reference = null,
        array $scriptureFilters = [],
    ): array {
        return [
            'section_key' => $sectionKey,
            'content_type' => TalkType::Sermon->value,
            'date' => '2026-08-02',
            'service' => SermonService::Morning->value,
            'slug' => $sectionKey === null ? 'persister-test-sermon' : 'persister-test-section-sermon',
            'filetype' => 'mp3',
            'title' => $sectionKey === null ? 'Persister test sermon' : 'Persister test section sermon',
            'reference' => $reference,
            'series' => null,
            'summary' => null,
            'meta_description' => null,
            'points' => null,
            'show_summary' => false,
            'show_points' => false,
            'duration' => 60.0,
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
                'name' => 'Persister Test Preacher',
                'slug' => 'persister-test-preacher',
                'aliases' => [],
            ],
            'scripture_filters' => $scriptureFilters,
            /**
             * HIR3: a publication carrying no passage must still say how it
             * settled. Omitting the outcome used to read as approval; it is now
             * refused, so the fixture states the terminal absence a curator
             * would have recorded.
             */
            'scripture_passage' => null,
            'scripture_passage_outcome' => $reference === null
                ? ['status' => 'approved_absent', 'reason' => 'source_has_no_passage']
                : ['status' => 'approved_absent', 'reason' => 'not_found'],
        ];
    }
}
