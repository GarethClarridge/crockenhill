<?php

declare(strict_types=1);

namespace Tests\Integration\Services\HistoricMedia;

use App\Enums\ProcessingStatus;
use App\Enums\SermonContentType;
use App\Enums\ServiceSectionType;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\SermonProcessingStep;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Models\SongVideo;
use App\Services\HistoricMedia\HistoricProcessingResultInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class HistoricProcessingResultInventoryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_builds_a_stable_graph_using_portable_relationship_keys(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->completed()->create([
            'processing_id' => 'historic-run',
            'processing_metadata' => [
                'service_artifacts' => [
                    ['kind' => 'rms', 'path' => 'historic/run/rms.json', 'sha256' => str_repeat('a', 64)],
                ],
                'owner_user_id' => 999,
                'queue_name' => 'media',
            ],
        ]);
        SermonProcessingStep::factory()->create([
            'processing_id' => $run->processing_id,
            'step' => 'transcription',
            'status' => ProcessingStatus::Completed,
        ]);
        $segment = LivestreamSegment::factory()->forProcessingLog($run->id)->withIndex(7)->create();
        $childrensTalk = Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
        ]);
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_order' => 2,
            'section_type' => ServiceSectionType::ChildrensTalk,
            'source_segment_ids' => [$segment->id],
            'published_sermon_id' => $childrensTalk->id,
        ]);
        $song = Song::factory()->create(['canonical_key' => 'amazing-grace']);
        SongVideo::factory()->create([
            'song_id' => $song->id,
            'service_section_id' => $section->id,
        ]);

        $first = app(HistoricProcessingResultInventory::class)->build($run);
        $second = app(HistoricProcessingResultInventory::class)->build($run->fresh());

        $this->assertSame($first['logical_hash'], $second['logical_hash']);
        $this->assertSame('historic-run:segment:7', $first['segments'][0]['segment_key']);
        $this->assertSame(
            ['historic-run:segment:7'],
            $first['sections'][0]['source_segment_keys'],
        );
        $this->assertContains('childrens_talk', array_column($first['publications'], 'content_type'));
        $this->assertSame('amazing-grace', $first['song_videos'][0]['song_canonical_key']);
        $this->assertArrayNotHasKey('owner_user_id', $first['run']);
        $this->assertArrayNotHasKey('queue_name', $first['run']);
        $this->assertArrayNotHasKey('owner_user_id', $first['metadata']);
        $this->assertArrayNotHasKey('queue_name', $first['metadata']);
    }

    #[Test]
    public function it_rejects_a_section_link_to_a_segment_outside_the_run(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->completed()->create();
        $otherRun = MediaProcessingLog::factory()->livestream()->completed()->create();
        $foreignSegment = LivestreamSegment::factory()->forProcessingLog($otherRun->id)->create();
        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'source_segment_ids' => [$foreignSegment->id],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('outside processing run');

        app(HistoricProcessingResultInventory::class)->build($run);
    }

    #[Test]
    public function its_logical_hash_survives_asset_path_and_import_metadata_remapping(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->completed()->create([
            'processing_id' => 'portable-round-trip',
            'audio_file_path' => 'historic/old/full-service.mp3',
            'video_file_path' => 'historic/old/full-service.mp4',
            'processing_metadata' => [
                'service_artifacts' => [[
                    'kind' => 'rms',
                    'path' => 'historic/old/rms.json',
                    'sha256' => str_repeat('a', 64),
                ]],
            ],
        ]);

        $before = app(HistoricProcessingResultInventory::class)->build($run);

        $run->forceFill([
            'audio_file_path' => 'service-transcripts/portable-round-trip/full-service.mp3',
            'video_file_path' => 'sermons/42/video.mp4',
            'processing_metadata' => [
                'service_artifacts' => [[
                    'kind' => 'rms',
                    'path' => 'service-transcripts/portable-round-trip/rms.json',
                    'sha256' => str_repeat('a', 64),
                ]],
                'historic_promotion' => [
                    'logical_hash' => str_repeat('b', 64),
                ],
            ],
        ])->save();

        $after = app(HistoricProcessingResultInventory::class)->build($run->fresh());

        $this->assertSame($before['logical_hash'], $after['logical_hash']);
        $this->assertNotSame(
            $before['run']['audio_file_path'],
            $after['run']['audio_file_path'],
        );
        $this->assertNotSame(
            $before['metadata']['service_artifacts'][0]['path'],
            $after['metadata']['service_artifacts'][0]['path'],
        );
    }

    #[Test]
    public function its_logical_hash_is_identical_across_different_database_ids(): void
    {
        $first = $this->buildRoundTripGraph();

        SongVideo::query()->delete();
        ServiceSection::query()->delete();
        LivestreamSegment::query()->delete();
        MediaProcessingLog::query()->where('processing_id', 'different-pk-run')->delete();
        Sermon::query()->delete();
        Song::query()->forceDelete();

        /** Advance every auto-increment the graph touches so nothing can match by luck. */
        Sermon::factory()->count(3)->create();
        Song::factory()->count(3)->create();
        MediaProcessingLog::factory()->count(3)->livestream()->completed()->create();

        $second = $this->buildRoundTripGraph();

        $this->assertNotSame(
            $first['identifiers'],
            $second['identifiers'],
            'The rebuilt graph reused its original primary keys, so this proves nothing.',
        );
        $this->assertSame($first['graph']['logical_hash'], $second['graph']['logical_hash']);
        $this->assertSame(
            $first['graph']['sections'][0]['section_key'],
            $second['graph']['sections'][0]['section_key'],
        );
        $this->assertSame(
            $first['graph']['song_videos'][0]['song_canonical_key'],
            $second['graph']['song_videos'][0]['song_canonical_key'],
        );
    }

    /**
     * @return array{graph: array<string, mixed>, identifiers: list<int>}
     */
    private function buildRoundTripGraph(): array
    {
        $run = MediaProcessingLog::factory()->livestream()->completed()->create([
            'processing_id' => 'different-pk-run',
            'audio_file_path' => 'historic/run/full-service.mp3',
            'original_filename' => 'full-service.mp3',
            'sermon_id' => null,
            'processing_metadata' => [],
        ]);
        $segment = LivestreamSegment::factory()->forProcessingLog($run->id)->withIndex(3)->create([
            'start_time' => 10.0,
            'end_time' => 60.0,
            'duration' => 50.0,
            'classification' => 'speech',
            'avg_rms' => 0.5,
            'peak_rms' => 0.9,
            'segment_order' => 1,
        ]);
        $sermon = Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'date' => '2026-08-02',
            'service' => 'morning',
            'slug' => 'a-portable-talk',
            'title' => 'A portable talk',
            'reference' => 'John 3:16',
            'series' => 'Portable series',
            'summary' => 'A portable summary.',
            'points' => ['One', 'Two'],
            'preacher' => 'Mark Drury',
        ]);
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_order' => 1,
            'section_type' => ServiceSectionType::ChildrensTalk,
            'title' => 'Childrens talk',
            'start_time' => 10.0,
            'end_time' => 60.0,
            'duration' => 50.0,
            'confidence' => 0.9,
            'extracted_at' => '2026-08-02T09:00:00Z',
            'published_at' => '2026-08-02T09:05:00Z',
            'source_segment_ids' => [$segment->id],
            'published_sermon_id' => $sermon->id,
        ]);
        $song = Song::factory()->create(['canonical_key' => 'portable-round-trip-song']);
        $songVideo = SongVideo::factory()->create([
            'song_id' => $song->id,
            'service_section_id' => $section->id,
            'recorded_date' => '2026-08-02',
            'duration' => 200.0,
            'is_featured' => false,
        ]);

        return [
            'graph' => app(HistoricProcessingResultInventory::class)->build($run->fresh()),
            'identifiers' => [$run->id, $segment->id, $sermon->id, $section->id, $song->id, $songVideo->id],
        ];
    }

    /**
     * Every pilot run carried these two shapes and every one failed portable
     * inventory on them: the run's own UUID inside the publication-candidate
     * extraction record, and the local speaker-profile row the speaker model
     * matched. The UUID travels; the profile row identity does not.
     */
    #[Test]
    public function it_carries_the_run_uuid_and_drops_the_local_speaker_profile_identity(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->completed()->create([
            'processing_id' => 'pilot-metadata-shapes',
        ]);
        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_order' => 1,
            'section_type' => ServiceSectionType::ChildrensTalk,
            'source_segment_ids' => [],
            'metadata' => [
                'publication_candidate_extraction' => [
                    'processing_id' => 'pilot-metadata-shapes',
                    'extracted_at' => '2026-08-29T08:51:59+00:00',
                ],
                'childrens_talk_speaker' => [
                    'predicted' => [
                        'outcome' => 'matched',
                        'confidence' => 0.975,
                        'preacher_id' => null,
                        'matched_profile_id' => 2,
                    ],
                ],
            ],
        ]);

        $graph = app(HistoricProcessingResultInventory::class)->build($run->fresh());
        $metadata = $graph['sections'][0]['metadata'];

        $this->assertSame(
            'pilot-metadata-shapes',
            $metadata['publication_candidate_extraction']['processing_id'],
        );
        $this->assertArrayNotHasKey(
            'matched_profile_id',
            $metadata['childrens_talk_speaker']['predicted'],
        );
        $this->assertSame(0.975, $metadata['childrens_talk_speaker']['predicted']['confidence']);
    }

    #[Test]
    public function it_rejects_unclassified_relative_metadata_paths(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->completed()->create([
            'processing_id' => 'metadata-path-guard',
            'processing_metadata' => [
                'service_structure' => [
                    'unexpected_path' => 'historic/structure.json',
                ],
            ],
        ]);

        $this->expectException(RuntimeException::class);

        app(HistoricProcessingResultInventory::class)->build($run);
    }
}
