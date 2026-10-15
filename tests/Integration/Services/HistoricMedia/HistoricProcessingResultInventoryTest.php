<?php

declare(strict_types=1);

namespace Tests\Integration\Services\HistoricMedia;

use App\Enums\ProcessingStatus;
use App\Enums\ServiceSectionType;
use App\Enums\TalkType;
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
            'content_type' => TalkType::ChildrensTalk,
        ]);
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_order' => 2,
            'section_type' => ServiceSectionType::ShortTalk,
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
            'content_type' => TalkType::ChildrensTalk,
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
            'section_type' => ServiceSectionType::ShortTalk,
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
            'section_type' => ServiceSectionType::ShortTalk,
            'source_segment_ids' => [],
            'metadata' => [
                'publication_candidate_extraction' => [
                    'processing_id' => 'pilot-metadata-shapes',
                    'extracted_at' => '2026-08-29T08:51:59+00:00',
                ],
                'talk_speaker' => [
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
            $metadata['talk_speaker']['predicted'],
        );
        $this->assertSame(0.975, $metadata['talk_speaker']['predicted']['confidence']);
    }

    /**
     * 2026-10-15: none of 368 completed historic runs passed export, each on a section metadata
     * field written after the export's classification: the sync's superseded signatures (359
     * runs), content holds (71), boundary inputs and a sermon continuation's sermon.
     */
    #[Test]
    public function it_classifies_the_section_metadata_the_round_writes(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->completed()->create([
            'processing_id' => 'round-section-metadata',
        ]);
        $sermon = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_order' => 1,
            'section_type' => ServiceSectionType::Sermon,
            'source_segment_ids' => [],
            'metadata' => [
                'superseded' => [
                    'previous_signature' => ['church_service_item_id' => 7173, 'start_time' => 10.0],
                    'next_signature' => ['church_service_item_id' => 7174, 'start_time' => 12.0],
                ],
                'content_holds' => [[
                    'reason' => 'short_transcript_loop_source_mismatch',
                    'start_time' => 10.0,
                    'end_time' => 900.0,
                    'transcript_sha256' => 'abc',
                    'church_service_item_id' => 7173,
                ]],
                'short_talk_boundary' => [
                    'inputs' => [
                        'service_transcript' => ['path' => 'service-transcripts/2022-12-10/morning-x.normalized.json', 'status' => 'available'],
                    ],
                    'decision' => 'kept',
                ],
            ],
        ]);
        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_order' => 2,
            'section_type' => ServiceSectionType::Other,
            'source_segment_ids' => [],
            'metadata' => [
                'sermon_continuation' => ['of_section_id' => $sermon->id, 'evidence' => 'This continues the sermon.', 'source' => 'detector_notes'],
            ],
        ]);

        $graph = app(HistoricProcessingResultInventory::class)->build($run->fresh());
        [$sermonPayload, $continuationPayload] = $graph['sections'];

        $this->assertArrayNotHasKey('superseded', $sermonPayload['metadata']);
        // The JSON column re-sorts keys and drops `.0`, so these compare by value.
        $this->assertEquals([[
            'reason' => 'short_transcript_loop_source_mismatch',
            'start_time' => 10.0,
            'end_time' => 900.0,
            'transcript_sha256' => 'abc',
        ]], $sermonPayload['metadata']['content_holds']);
        $this->assertSame(
            'service-transcripts/2022-12-10/morning-x.normalized.json',
            $sermonPayload['metadata']['short_talk_boundary']['inputs']['service_transcript']['path'],
        );
        $this->assertEquals([
            'of_section_key' => $sermonPayload['section_key'],
            'evidence' => 'This continues the sermon.',
            'source' => 'detector_notes',
        ], $continuationPayload['metadata']['sermon_continuation']);
    }

    /**
     * The section, song and candidate references the round's decisions record, at any depth:
     * sections of the run become their keys, songs their canonical keys, candidate UUIDs travel,
     * and a null identity names nothing local.
     */
    #[Test]
    public function it_makes_section_song_and_candidate_references_portable(): void
    {
        $song = Song::factory()->create(['canonical_key' => 'song:amazing-grace']);
        $run = MediaProcessingLog::factory()->livestream()->completed()->create(['processing_id' => 'portable-references']);
        $reading = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id, 'church_service_item_id' => null, 'section_order' => 1,
            'section_type' => ServiceSectionType::BibleReading, 'source_segment_ids' => [],
        ]);
        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id, 'church_service_item_id' => null, 'section_order' => 2,
            'section_type' => ServiceSectionType::Sermon, 'source_segment_ids' => [],
            'metadata' => [
                'sermon_boundary' => [
                    'sermon_section_id' => null,
                    'bible_section_id' => $reading->id,
                    'selected_section_ids' => [$reading->id],
                ],
                'short_talk_boundary' => ['following_sections' => [['id' => $reading->id, 'type' => 'bible_reading']]],
                'lyric_identity_check' => ['bound_song_id' => $song->id, 'rival_song_id' => null],
                'publication_candidate_extraction' => ['candidate_id' => 'f5075315-2c1f-4e4f-8fd4-a99ed2ef35ea'],
                'publication' => ['demoted' => ['previously_published_sermon_id' => null]],
            ],
        ]);

        $graph = app(HistoricProcessingResultInventory::class)->build($run->fresh());
        $readingKey = $graph['sections'][0]['section_key'];
        $metadata = $graph['sections'][1]['metadata'];

        $this->assertEquals(['sermon_section_key' => null, 'bible_section_key' => $readingKey, 'selected_section_keys' => [$readingKey]], $metadata['sermon_boundary']);
        $this->assertSame([['section_key' => $readingKey, 'type' => 'bible_reading']], $metadata['short_talk_boundary']['following_sections']);
        $this->assertEquals(['bound_song_canonical_key' => 'song:amazing-grace', 'rival_song_canonical_key' => null], $metadata['lyric_identity_check']);
        $this->assertSame('f5075315-2c1f-4e4f-8fd4-a99ed2ef35ea', $metadata['publication_candidate_extraction']['candidate_id']);
        $this->assertNull($metadata['publication']['demoted']['previously_published_sermon_id']);
    }

    #[Test]
    public function it_still_rejects_a_local_identity_it_cannot_translate(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->completed()->create(['processing_id' => 'untranslatable-identity']);
        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id, 'church_service_item_id' => null, 'section_order' => 1, 'source_segment_ids' => [],
            'metadata' => ['publication' => ['demoted' => ['previously_published_sermon_id' => 42]]],
        ]);

        $this->expectException(RuntimeException::class);

        app(HistoricProcessingResultInventory::class)->build($run->fresh());
    }

    #[Test]
    public function it_rejects_an_absolute_boundary_input_path(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->completed()->create(['processing_id' => 'absolute-boundary-input']);
        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_order' => 1,
            'source_segment_ids' => [],
            'metadata' => ['song_publication_boundary' => ['inputs' => ['rms_log' => ['path' => '/var/www/html/storage/x.rms.json']]]],
        ]);

        $this->expectException(RuntimeException::class);

        app(HistoricProcessingResultInventory::class)->build($run->fresh());
    }

    #[Test]
    public function it_rejects_a_sermon_continuation_naming_another_runs_section(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->completed()->create(['processing_id' => 'foreign-continuation']);
        $foreign = ServiceSection::factory()->create(['source_segment_ids' => []]);
        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_order' => 1,
            'source_segment_ids' => [],
            'metadata' => ['sermon_continuation' => ['of_section_id' => $foreign->id, 'evidence' => 'x']],
        ]);

        $this->expectException(RuntimeException::class);

        app(HistoricProcessingResultInventory::class)->build($run->fresh());
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
