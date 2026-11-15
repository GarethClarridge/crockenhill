<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ChurchServiceSource;
use App\Enums\ProcessingStatus;
use App\Enums\SermonPublicationState;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceSourceRecord;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\SermonProcessingStep;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Models\SongVideo;
use App\Services\HistoricMedia\QueuedRunJobs;
use App\Services\HistoricMedia\RetiredRunPurge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class PurgeRetiredHistoricRunCommandTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    private HistoricImportOperation $operation;

    /** @var list<string> */
    private array $queuedJobs = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'media-processing.storage.temp_disk' => 'purge_temp',
            'media-processing.storage.sermon_disk' => 'historic_quarantine',
            'media-processing.storage.transcript_disk' => 'purge_artifacts',
            'media-processing.storage.service_artifact_disk' => 'purge_artifacts',
        ]);

        foreach (['purge_temp', 'purge_artifacts', 'historic_quarantine'] as $disk) {
            Storage::fake($disk);
        }

        /** @var MockInterface $queued */
        $queued = Mockery::mock(QueuedRunJobs::class);
        $queued->shouldReceive('naming')->andReturnUsing(fn (): array => $this->queuedJobs);
        $this->instance(QueuedRunJobs::class, $queued);

        $this->operation = $this->createHistoricImportOperation();
    }

    /**
     * A run exactly as `historic-import:retire-run` leaves it, with the content retirement does
     * not remove: staged source, service transcript and artifacts, an unpublished section with
     * extracted media, a song clip, source evidence and run-derived church service text.
     *
     * @return array{run: MediaProcessingLog, sermon: Sermon, service: ChurchService, section: ServiceSection, clip: SongVideo, record: ChurchServiceSourceRecord, files: list<array{0: string, 1: string}>}
     */
    private function retiredRun(): array
    {
        $pid = 'aaaaaaaa-0000-4000-8000-000000000944';
        $service = ChurchService::factory()->create([
            'date' => '2024-05-12',
            'service' => 'evening',
            'summary' => 'Run summary',
            'notices' => [],
            'chapter_markers' => [['title' => 'Marker', 'start_time' => 10, 'end_time' => 20]],
            'import_metadata' => [
                'confidence_score' => 1.0,
                'livestream_projection' => ['projected_at' => '2026-11-01T10:00:00+00:00'],
                'canonical_conflict_history' => [
                    ['incoming_source' => 'livestream', 'changes' => [['title' => 'Run title']]],
                    ['incoming_source' => 'email', 'changes' => [['title' => 'Email title']]],
                ],
            ],
        ]);

        $sermon = Sermon::factory()->create([
            'publication_state' => SermonPublicationState::Quarantined,
            'asset_disk' => 'historic_quarantine',
            'video_file_path' => 'sermons/944/video.mp4',
            'audio_file_path' => 'sermons/audio/944.mp3',
            'transcript_file_path' => null,
            'thumbnail_file_path' => null,
        ]);
        Storage::disk('historic_quarantine')->put('sermons/944/video.mp4', 'sermon-video');
        Storage::disk('historic_quarantine')->put('sermons/audio/944.mp3', 'sermon-audio');

        $transcript = "service-transcripts/2024-05-12/evening-{$pid}.json";
        $rms = "service-transcripts/2024-05-12/evening-{$pid}.rms.json";
        $classes = "service-transcripts/2024-05-12/evening-{$pid}.classes.json";
        $edge = "service-transcripts/2024-05-12/evening-{$pid}.edge-words.json";
        $ensembleInput = "service-transcripts/{$pid}.ensemble.a1.input.json";
        $ensembleSlot = "service-transcripts/{$pid}.ensemble.a1.slot-0.json";

        foreach ([$transcript, $rms, $classes, $edge, $ensembleInput, $ensembleSlot] as $path) {
            Storage::disk('purge_artifacts')->put($path, 'artifact '.$path);
        }

        Storage::disk('purge_temp')->put('livestream/temp/source-944.mkv', 'staged-source');

        $run = MediaProcessingLog::factory()->create([
            'processing_id' => $pid,
            'historic_import_operation_id' => $this->operation->id,
            'status' => ProcessingStatus::Completed,
            'current_step' => 'completed',
            'ai_analysis' => ['title' => 'Rehearsal analysis title'],
            'error_message' => 'Rehearsal error text',
            'rms_stats' => ['mean' => -40.5],
            'sermon_id' => $sermon->id,
            'church_service_id' => $service->id,
            'extracted_date' => '2024-05-12',
            'extracted_service' => 'evening',
            'source_file_path' => 'livestream/temp/source-944.mkv',
            'enhanced_audio_file_path' => null,
            'audio_file_path' => null,
            'video_file_path' => null,
            'transcript_file_path' => null,
            'rms_log_path' => $rms,
            'audio_timeline_path' => $classes,
            'processing_metadata' => [
                'historic_import' => [
                    'operation_id' => $this->operation->operation_id,
                    'manifest_item_key' => '2024-05-12-evening',
                    'editorial_facts' => ['title' => 'Rehearsal editorial title', 'speaker' => 'Rehearsal speaker'],
                    'sources' => [['path' => '/mnt/cbc-services/2024-05-12/Evening/18-08.mkv']],
                ],
                'service_transcript_path' => $transcript,
                'service_structure' => ['summary' => 'Rehearsal structure summary', 'sections' => [['title' => 'Rehearsal section']]],
                'speaker_identification' => ['outcome' => 'Rehearsal speaker'],
                'sermon_extraction_plan' => ['reason' => 'Rehearsal plan'],
                'transcript_content' => ['hash' => 'abc', 'changed_at' => '2026-11-01T00:00:00Z'],
                'service_artifacts' => [
                    ['kind' => 'normalized', 'disk' => 'purge_artifacts', 'path' => $transcript],
                    ['kind' => 'rms', 'disk' => 'purge_artifacts', 'path' => $rms],
                    ['kind' => 'classes', 'disk' => 'purge_artifacts', 'path' => $classes],
                    ['kind' => 'edge-words', 'disk' => 'purge_artifacts', 'path' => $edge],
                ],
                'service_structure_ensemble' => [[
                    'attempt_id' => 'a1',
                    'artifact_disk' => 'purge_artifacts',
                    'input_path' => $ensembleInput,
                    'slots' => [['slot' => 0, 'path' => $ensembleSlot]],
                ]],
            ],
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Song,
            'section_order' => 1,
            'title' => 'Rehearsal song',
            'extracted_video_path' => 'section-publications/1-abc/video.mp4',
            'extracted_audio_path' => 'section-publications/1-abc/audio.mp3',
            'asset_disk' => 'historic_quarantine',
        ]);
        Storage::disk('historic_quarantine')->put('section-publications/1-abc/video.mp4', 'section-video');
        Storage::disk('historic_quarantine')->put('section-publications/1-abc/audio.mp3', 'section-audio');

        $clip = SongVideo::factory()->quarantined()->create([
            'song_id' => Song::factory()->create()->id,
            'service_section_id' => $section->id,
            'video_file_path' => 'sermons/songs/944-clip.mp4',
            'asset_disk' => 'historic_quarantine',
        ]);
        Storage::disk('historic_quarantine')->put('sermons/songs/944-clip.mp4', 'clip-bytes');

        $record = ChurchServiceSourceRecord::factory()->create([
            'church_service_id' => $service->id,
            'source' => ChurchServiceSource::Livestream,
            'source_key' => $pid.'|v1',
            'service_content' => [
                'summary' => 'Run summary',
                'notices' => [],
                'chapter_markers' => [['title' => 'Marker', 'start_time' => 10, 'end_time' => 20]],
            ],
        ]);

        SermonProcessingStep::factory()->create(['processing_id' => $pid, 'step' => 'analyzing', 'message' => 'Rehearsal step text']);

        $this->artisan('historic-import:retire-run', [
            '--operation' => $this->operation->operation_id,
            '--processing-id' => [$pid],
            '--note' => 'Second recording is a rehearsal.',
            '--apply' => true,
            '--yes' => true,
        ])->assertSuccessful();

        $prefix = 'superseded/'.$this->operation->operation_id.'/'.$sermon->id;

        return [
            'run' => $run->fresh() ?? $run,
            'sermon' => $sermon,
            'service' => $service,
            'section' => $section,
            'clip' => $clip,
            'record' => $record,
            'files' => [
                ['purge_temp', 'livestream/temp/source-944.mkv'],
                ['purge_artifacts', $transcript],
                ['purge_artifacts', $rms],
                ['purge_artifacts', $classes],
                ['purge_artifacts', $edge],
                ['purge_artifacts', $ensembleInput],
                ['purge_artifacts', $ensembleSlot],
                ['historic_quarantine', 'section-publications/1-abc/video.mp4'],
                ['historic_quarantine', 'section-publications/1-abc/audio.mp3'],
                ['historic_quarantine', 'sermons/songs/944-clip.mp4'],
                ['historic_quarantine', $prefix.'/video-video.mp4'],
                ['historic_quarantine', $prefix.'/audio-944.mp3'],
                ['historic_quarantine', $prefix.'/retirement.json'],
            ],
        ];
    }

    /**
     * Another run's content on the same disks, which a purge must never touch.
     *
     * @return array{run: MediaProcessingLog, section: ServiceSection, clip: SongVideo, files: list<array{0: string, 1: string}>}
     */
    private function unrelatedRun(): array
    {
        Storage::disk('purge_temp')->put('livestream/temp/other.mkv', 'other-source');
        Storage::disk('purge_artifacts')->put('service-transcripts/2024-05-19/morning-other.json', 'other-transcript');
        Storage::disk('historic_quarantine')->put('section-publications/2-def/video.mp4', 'other-section');
        Storage::disk('historic_quarantine')->put('sermons/songs/other-clip.mp4', 'other-clip');

        $run = MediaProcessingLog::factory()->create([
            'historic_import_operation_id' => $this->operation->id,
            'status' => ProcessingStatus::Completed,
            'extracted_date' => '2024-05-19',
            'extracted_service' => 'morning',
            'source_file_path' => 'livestream/temp/other.mkv',
            'enhanced_audio_file_path' => null,
            'audio_file_path' => null,
            'video_file_path' => null,
            'transcript_file_path' => null,
            'rms_log_path' => null,
            'audio_timeline_path' => null,
            'processing_metadata' => [
                'service_transcript_path' => 'service-transcripts/2024-05-19/morning-other.json',
                'service_artifacts' => [['kind' => 'normalized', 'disk' => 'purge_artifacts', 'path' => 'service-transcripts/2024-05-19/morning-other.json']],
            ],
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_order' => 1,
            'extracted_video_path' => 'section-publications/2-def/video.mp4',
            'asset_disk' => 'historic_quarantine',
        ]);
        $clip = SongVideo::factory()->quarantined()->create([
            'song_id' => Song::factory()->create()->id,
            'service_section_id' => $section->id,
            'video_file_path' => 'sermons/songs/other-clip.mp4',
            'asset_disk' => 'historic_quarantine',
        ]);

        return [
            'run' => $run,
            'section' => $section,
            'clip' => $clip,
            'files' => [
                ['purge_temp', 'livestream/temp/other.mkv'],
                ['purge_artifacts', 'service-transcripts/2024-05-19/morning-other.json'],
                ['historic_quarantine', 'section-publications/2-def/video.mp4'],
                ['historic_quarantine', 'sermons/songs/other-clip.mp4'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function applyArgs(MediaProcessingLog $run): array
    {
        return ['--processing-id' => $run->processing_id, '--apply' => true, '--yes' => true];
    }

    /** @param list<array{0: string, 1: string}> $files */
    private function assertAllExist(array $files): void
    {
        foreach ($files as [$disk, $path]) {
            Storage::disk($disk)->assertExists($path);
        }
    }

    /** @param list<array{0: string, 1: string}> $files */
    private function assertAllMissing(array $files): void
    {
        foreach ($files as [$disk, $path]) {
            Storage::disk($disk)->assertMissing($path);
        }
    }

    #[Test]
    public function the_dry_run_lists_the_exact_inventory_and_writes_nothing(): void
    {
        $fixture = $this->retiredRun();

        $this->artisan('historic-import:purge-retired-run', ['--processing-id' => $fixture['run']->processing_id])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('purge_temp:livestream/temp/source-944.mkv')
            ->expectsOutputToContain('historic_quarantine:section-publications/1-abc/video.mp4')
            ->expectsOutputToContain('service_sections #'.$fixture['section']->id)
            ->expectsOutputToContain('song_videos #'.$fixture['clip']->id)
            ->expectsOutputToContain('church_service_source_records #'.$fixture['record']->id)
            ->expectsOutputToContain('import_metadata.canonical_conflict_history[0]')
            ->assertSuccessful();

        $this->assertAllExist($fixture['files']);
        $this->assertNotNull(ServiceSection::query()->find($fixture['section']->id));
        $this->assertNotNull(SongVideo::query()->find($fixture['clip']->id));
        $this->assertNotNull(ChurchServiceSourceRecord::query()->find($fixture['record']->id));
        $this->assertNull($fixture['run']->fresh()?->purgeRecord());
        $this->assertSame('Run summary', $fixture['service']->fresh()?->summary);
    }

    #[Test]
    public function it_refuses_a_run_that_was_never_retired(): void
    {
        Storage::disk('purge_temp')->put('livestream/temp/live.mkv', 'live');
        $run = MediaProcessingLog::factory()->create([
            'historic_import_operation_id' => $this->operation->id,
            'status' => ProcessingStatus::Completed,
            'source_file_path' => 'livestream/temp/live.mkv',
        ]);

        $this->artisan('historic-import:purge-retired-run', $this->applyArgs($run))
            ->expectsOutputToContain('not retired through historic-import:retire-run')
            ->assertFailed();

        Storage::disk('purge_temp')->assertExists('livestream/temp/live.mkv');
    }

    #[Test]
    public function it_refuses_a_superseded_run_that_has_no_retirement_record(): void
    {
        $run = MediaProcessingLog::factory()->create([
            'historic_import_operation_id' => $this->operation->id,
            'status' => ProcessingStatus::Completed,
            'superseded_at' => now(),
        ]);

        $this->artisan('historic-import:purge-retired-run', $this->applyArgs($run))
            ->expectsOutputToContain('superseded marker but no retirement record')
            ->assertFailed();
    }

    #[Test]
    public function it_refuses_while_a_linked_sermon_is_published(): void
    {
        $fixture = $this->retiredRun();
        Sermon::factory()->create([
            'publication_state' => SermonPublicationState::Published,
            'livestream_processing_id' => $fixture['run']->processing_id,
        ]);

        $this->artisan('historic-import:purge-retired-run', $this->applyArgs($fixture['run']))
            ->expectsOutputToContain('still exists and is published')
            ->assertFailed();

        $this->assertAllExist($fixture['files']);
        $this->assertNull($fixture['run']->fresh()?->purgeRecord());
    }

    #[Test]
    public function it_refuses_while_a_section_is_published(): void
    {
        $fixture = $this->retiredRun();
        $fixture['section']->forceFill([
            'publication_status' => ServiceSectionPublicationStatus::Published,
            'published_at' => now(),
        ])->save();

        $this->artisan('historic-import:purge-retired-run', $this->applyArgs($fixture['run']))
            ->expectsOutputToContain("Section [{$fixture['section']->id}] is published")
            ->assertFailed();

        $this->assertAllExist($fixture['files']);
        $this->assertNotNull(ServiceSection::query()->find($fixture['section']->id));
    }

    #[Test]
    public function it_refuses_while_a_song_clip_is_published(): void
    {
        $fixture = $this->retiredRun();
        $fixture['clip']->forceFill(['publication_state' => SermonPublicationState::Published])->save();

        $this->artisan('historic-import:purge-retired-run', $this->applyArgs($fixture['run']))
            ->expectsOutputToContain("Song clip [{$fixture['clip']->id}] is published")
            ->assertFailed();

        $this->assertAllExist($fixture['files']);
    }

    #[Test]
    public function it_refuses_a_run_that_is_not_terminal(): void
    {
        $fixture = $this->retiredRun();
        $fixture['run']->forceFill(['status' => ProcessingStatus::Processing])->save();

        $this->artisan('historic-import:purge-retired-run', $this->applyArgs($fixture['run']))
            ->expectsOutputToContain('still in flight')
            ->assertFailed();

        $this->assertAllExist($fixture['files']);
    }

    #[Test]
    public function it_refuses_while_a_job_for_the_run_is_queued(): void
    {
        $fixture = $this->retiredRun();
        $this->queuedJobs = ['waiting TranscribeFullService on default'];

        $this->artisan('historic-import:purge-retired-run', $this->applyArgs($fixture['run']))
            ->expectsOutputToContain('still has queued jobs')
            ->assertFailed();

        $this->assertAllExist($fixture['files']);
    }

    #[Test]
    public function it_refuses_on_a_checksum_mismatch_and_deletes_nothing(): void
    {
        $fixture = $this->retiredRun();
        $prefix = 'superseded/'.$this->operation->operation_id.'/'.$fixture['sermon']->id;
        Storage::disk('historic_quarantine')->put($prefix.'/audio-944.mp3', 'tampered');

        $this->artisan('historic-import:purge-retired-run', $this->applyArgs($fixture['run']))
            ->expectsOutputToContain('Checksum mismatch')
            ->assertFailed();

        $this->assertAllExist($fixture['files']);
        $this->assertNotNull(ServiceSection::query()->find($fixture['section']->id));
        $this->assertNull($fixture['run']->fresh()?->purgeRecord());
    }

    #[Test]
    public function it_refuses_a_missing_checksum_in_the_retirement_record(): void
    {
        $fixture = $this->retiredRun();
        $run = $fixture['run'];
        $run->writeProcessingMetadata(function (array $metadata): array {
            $metadata['retirement']['assets'][0]['sha256'] = '';

            return $metadata;
        });

        $this->artisan('historic-import:purge-retired-run', $this->applyArgs($run))
            ->expectsOutputToContain('no recorded sha256')
            ->assertFailed();

        $this->assertAllExist($fixture['files']);
    }

    #[Test]
    public function it_refuses_a_file_a_newer_active_run_of_the_same_service_still_references(): void
    {
        $fixture = $this->retiredRun();
        $newer = MediaProcessingLog::factory()->create([
            'historic_import_operation_id' => $this->operation->id,
            'status' => ProcessingStatus::Completed,
            'church_service_id' => $fixture['service']->id,
            'extracted_date' => '2024-05-12',
            'extracted_service' => 'evening',
            'source_file_path' => 'livestream/temp/source-944.mkv',
        ]);

        $this->artisan('historic-import:purge-retired-run', $this->applyArgs($fixture['run']))
            ->expectsOutputToContain("still referenced by active run [{$newer->processing_id}]")
            ->assertFailed();

        $this->assertAllExist($fixture['files']);
        $this->assertNotNull(ServiceSection::query()->find($fixture['section']->id));
    }

    #[Test]
    public function apply_deletes_exactly_the_enumerated_files_and_rows_and_nothing_else(): void
    {
        $fixture = $this->retiredRun();
        $other = $this->unrelatedRun();
        $operationRows = HistoricImportOperation::query()->count();

        $this->artisan('historic-import:purge-retired-run', $this->applyArgs($fixture['run']))->assertSuccessful();

        $this->assertAllMissing($fixture['files']);
        $this->assertNull(ServiceSection::query()->find($fixture['section']->id));
        $this->assertNull(SongVideo::query()->find($fixture['clip']->id));
        $this->assertNull(ChurchServiceSourceRecord::query()->find($fixture['record']->id));

        $this->assertAllExist($other['files']);
        $this->assertNotNull(ServiceSection::query()->find($other['section']->id));
        $this->assertNotNull(SongVideo::query()->find($other['clip']->id));
        $this->assertNotNull(MediaProcessingLog::query()->find($other['run']->id));
        $this->assertSame($operationRows, HistoricImportOperation::query()->count());

        $run = $fixture['run']->fresh();
        $this->assertNotNull($run, 'The run row stays as the tombstone.');
        $this->assertNotNull($run->retirementRecord());
        $purge = $run->purgeRecord();
        $this->assertIsArray($purge);
        $this->assertSame('completed', $purge['status']);
        $this->assertNotEmpty($purge['purged_at']);
        $this->assertCount(13, $purge['files']);
        $staged = collect($purge['files'])->firstWhere('path', 'livestream/temp/source-944.mkv');
        $this->assertSame(hash('sha256', 'staged-source'), $staged['sha256']);
        $this->assertSame(strlen('staged-source'), $staged['bytes']);
        $this->assertArrayNotHasKey('service_artifacts', $run->processing_metadata?->toArray() ?? []);
        $this->assertArrayNotHasKey('service_structure_ensemble', $run->processing_metadata?->toArray() ?? []);

        // The directory the section exclusively owned goes once its files have.
        $this->assertEmpty(Storage::disk('historic_quarantine')->allFiles('section-publications/1-abc'));
    }

    #[Test]
    public function it_never_deletes_the_archive_master(): void
    {
        $fixture = $this->retiredRun();
        $fixture['run']->forceFill(['source_file_path' => '/mnt/cbc-services/2024-05-12/Evening/18-08.mkv'])->save();

        $this->artisan('historic-import:purge-retired-run', ['--processing-id' => $fixture['run']->processing_id])
            ->expectsOutputToContain('names the archive master')
            ->assertSuccessful();
    }

    #[Test]
    public function the_church_service_keeps_other_sources_and_loses_only_this_runs_text(): void
    {
        $fixture = $this->retiredRun();
        $email = ChurchServiceSourceRecord::factory()->create([
            'church_service_id' => $fixture['service']->id,
            'source' => ChurchServiceSource::Email,
            'source_key' => 'email-944',
            'service_content' => ['summary' => 'Email summary', 'notices' => [['title' => 'Notice', 'details' => null]]],
        ]);

        $this->artisan('historic-import:purge-retired-run', $this->applyArgs($fixture['run']))->assertSuccessful();

        $service = $fixture['service']->fresh();
        $this->assertNotNull($service);
        $this->assertNull($service->summary);
        $this->assertSame([], $service->chapter_markers);
        $this->assertNotNull(ChurchServiceSourceRecord::query()->find($email->id));

        $metadata = $service->import_metadata?->toArray() ?? [];
        $this->assertArrayNotHasKey('livestream_projection', $metadata);
        $this->assertCount(1, $metadata['canonical_conflict_history']);
        $this->assertSame('email', $metadata['canonical_conflict_history'][0]['incoming_source']);
        $this->assertSame(1.0, $metadata['confidence_score']);
    }

    #[Test]
    public function text_another_source_also_offers_stays_on_the_church_service(): void
    {
        $fixture = $this->retiredRun();
        ChurchServiceSourceRecord::factory()->create([
            'church_service_id' => $fixture['service']->id,
            'source' => ChurchServiceSource::OpenLp,
            'source_key' => 'openlp-944',
            'service_content' => ['summary' => 'Run summary'],
        ]);

        $this->artisan('historic-import:purge-retired-run', $this->applyArgs($fixture['run']))->assertSuccessful();

        $service = $fixture['service']->fresh();
        $this->assertSame('Run summary', $service?->summary);
        $this->assertSame([], $service?->chapter_markers);
    }

    #[Test]
    public function a_second_apply_reports_nothing_to_do_and_succeeds(): void
    {
        $fixture = $this->retiredRun();

        $this->artisan('historic-import:purge-retired-run', $this->applyArgs($fixture['run']))->assertSuccessful();
        $purgedAt = $fixture['run']->fresh()?->purgeRecord()['purged_at'] ?? null;

        $this->artisan('historic-import:purge-retired-run', $this->applyArgs($fixture['run']))
            ->expectsOutputToContain('already purged; nothing to do')
            ->assertSuccessful();

        $this->assertSame($purgedAt, $fixture['run']->fresh()?->purgeRecord()['purged_at'] ?? null);
    }

    #[Test]
    public function an_interrupted_purge_resumes_and_finishes_the_files(): void
    {
        $fixture = $this->retiredRun();

        $this->artisan('historic-import:purge-retired-run', $this->applyArgs($fixture['run']))->assertSuccessful();

        // Simulate a crash after the commit: the record says one file is still owed.
        Storage::disk('purge_temp')->put('livestream/temp/source-944.mkv', 'staged-source');
        $run = $fixture['run']->fresh();
        $this->assertNotNull($run);
        $run->writeProcessingMetadata(function (array $metadata): array {
            $metadata['purge']['status'] = 'files_pending';
            unset($metadata['purge']['purged_at']);

            foreach ($metadata['purge']['files'] as $index => $file) {
                if ($file['path'] === 'livestream/temp/source-944.mkv') {
                    $metadata['purge']['files'][$index]['deleted_at'] = null;
                }
            }

            return $metadata;
        });

        $this->artisan('historic-import:purge-retired-run', ['--processing-id' => $run->processing_id])
            ->expectsOutputToContain('Resuming an earlier purge')
            ->expectsOutputToContain('purge_temp:livestream/temp/source-944.mkv')
            ->assertSuccessful();
        Storage::disk('purge_temp')->assertExists('livestream/temp/source-944.mkv');

        $this->artisan('historic-import:purge-retired-run', $this->applyArgs($run))->assertSuccessful();

        Storage::disk('purge_temp')->assertMissing('livestream/temp/source-944.mkv');
        $this->assertSame('completed', $run->fresh()?->purgeRecord()['status'] ?? null);
    }

    #[Test]
    public function apply_without_confirmation_deletes_nothing(): void
    {
        $fixture = $this->retiredRun();

        $this->artisan('historic-import:purge-retired-run', ['--processing-id' => $fixture['run']->processing_id, '--apply' => true])
            ->expectsConfirmation('Permanently delete the content of retired run ['.$fixture['run']->processing_id.']?', 'no')
            ->assertFailed();

        $this->assertAllExist($fixture['files']);
        $this->assertNull($fixture['run']->fresh()?->purgeRecord());
    }

    #[Test]
    public function a_retired_weekly_run_with_no_historic_operation_is_accepted(): void
    {
        Storage::disk('purge_temp')->put('livestream/temp/weekly.mkv', 'weekly-source');
        Storage::disk('purge_artifacts')->put('service-transcripts/2026-11-08/morning-weekly.json', 'weekly-transcript');

        $run = MediaProcessingLog::factory()->create([
            'historic_import_operation_id' => null,
            'status' => ProcessingStatus::Completed,
            'source_file_path' => 'livestream/temp/weekly.mkv',
            'enhanced_audio_file_path' => null,
            'audio_file_path' => null,
            'video_file_path' => null,
            'transcript_file_path' => null,
            'rms_log_path' => null,
            'audio_timeline_path' => null,
            'superseded_at' => now(),
            'processing_metadata' => [
                'service_transcript_path' => 'service-transcripts/2026-11-08/morning-weekly.json',
            ],
        ]);
        $run->putRetirement(['recorded_by' => 'operator', 'note' => 'Replaced.', 'sermon' => null, 'assets' => []]);

        $this->artisan('historic-import:purge-retired-run', $this->applyArgs($run))->assertSuccessful();

        Storage::disk('purge_temp')->assertMissing('livestream/temp/weekly.mkv');
        Storage::disk('purge_artifacts')->assertMissing('service-transcripts/2026-11-08/morning-weekly.json');
        $this->assertSame('completed', $run->fresh()?->purgeRecord()['status'] ?? null);
    }

    #[Test]
    public function it_dispatches_no_jobs(): void
    {
        $fixture = $this->retiredRun();
        Queue::fake();

        $this->artisan('historic-import:purge-retired-run', ['--processing-id' => $fixture['run']->processing_id])->assertSuccessful();
        $this->artisan('historic-import:purge-retired-run', $this->applyArgs($fixture['run']))->assertSuccessful();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_dry_run_issues_no_write_query_and_fires_no_model_event(): void
    {
        $fixture = $this->retiredRun();
        $writes = [];
        DB::listen(static function ($query) use (&$writes): void {
            if (! preg_match('/^\s*(select|show|set|savepoint|release)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $events = [];
        foreach ([MediaProcessingLog::class, ServiceSection::class, SongVideo::class, ChurchServiceSourceRecord::class, SermonProcessingStep::class] as $model) {
            $model::saving(static function () use (&$events, $model): void {
                $events[] = $model;
            });
            $model::deleting(static function () use (&$events, $model): void {
                $events[] = $model;
            });
        }

        $this->artisan('historic-import:purge-retired-run', ['--processing-id' => $fixture['run']->processing_id])->assertSuccessful();

        $this->assertSame([], $writes);
        $this->assertSame([], $events);
        $this->assertAllExist($fixture['files']);
    }

    #[Test]
    public function it_refuses_a_disk_it_cannot_see_instead_of_recording_its_files_as_deleted(): void
    {
        $fixture = $this->retiredRun();
        File::deleteDirectory(Storage::disk('purge_temp')->path(''));

        $this->artisan('historic-import:purge-retired-run', $this->applyArgs($fixture['run']))
            ->expectsOutputToContain('Cannot see disk [purge_temp]')
            ->assertFailed();

        $this->assertNull($fixture['run']->fresh()?->purgeRecord());
        $this->assertNotNull(ServiceSection::query()->find($fixture['section']->id));
        Storage::disk('purge_artifacts')->assertExists("service-transcripts/2024-05-12/evening-{$fixture['run']->processing_id}.json");
    }

    #[Test]
    public function it_never_deletes_a_file_a_still_linked_quarantined_sermon_owns(): void
    {
        $fixture = $this->retiredRun();
        Storage::disk('historic_quarantine')->put('sermons/linked/video.mp4', 'linked-video');
        Sermon::factory()->create([
            'publication_state' => SermonPublicationState::Quarantined,
            'livestream_processing_id' => $fixture['run']->processing_id,
            'asset_disk' => 'historic_quarantine',
            'video_file_path' => 'sermons/linked/video.mp4',
        ]);
        $fixture['run']->forceFill(['video_file_path' => 'sermons/linked/video.mp4'])->save();

        $this->artisan('historic-import:purge-retired-run', $this->applyArgs($fixture['run']))
            ->expectsOutputToContain('still used by sermon')
            ->assertFailed();

        Storage::disk('historic_quarantine')->assertExists('sermons/linked/video.mp4');
        $this->assertAllExist($fixture['files']);
    }

    #[Test]
    public function it_refuses_a_run_that_another_active_run_references_by_path_even_on_another_service(): void
    {
        $fixture = $this->retiredRun();
        MediaProcessingLog::factory()->create([
            'historic_import_operation_id' => $this->operation->id,
            'status' => ProcessingStatus::Completed,
            'extracted_date' => '2030-01-01',
            'source_file_path' => 'livestream/temp/source-944.mkv',
        ]);

        $this->artisan('historic-import:purge-retired-run', $this->applyArgs($fixture['run']))
            ->expectsOutputToContain('still referenced by active run')
            ->assertFailed();

        $this->assertAllExist($fixture['files']);
    }

    #[Test]
    public function a_resumed_purge_refuses_a_recorded_path_it_could_not_have_written(): void
    {
        $fixture = $this->retiredRun();
        $run = $fixture['run'];
        $run->putPurge([
            'status' => 'files_pending',
            'files' => [
                ['kind' => 'staged_source', 'disk' => 'purge_temp', 'path' => '../../.env', 'bytes' => 1, 'sha256' => null, 'existed' => true, 'deleted_at' => null],
            ],
        ]);

        $this->artisan('historic-import:purge-retired-run', $this->applyArgs($run))
            ->expectsOutputToContain('not a path this command could have recorded')
            ->assertFailed();

        $run->putPurge([
            'status' => 'files_pending',
            'files' => [
                ['kind' => 'staged_source', 'disk' => '(absolute)', 'path' => '/etc/hosts', 'bytes' => 1, 'sha256' => null, 'existed' => true, 'deleted_at' => null],
            ],
        ]);

        $this->artisan('historic-import:purge-retired-run', $this->applyArgs($run))
            ->expectsOutputToContain('not a path this command could have recorded')
            ->assertFailed();
    }

    #[Test]
    public function the_tombstone_keeps_only_allow_listed_metadata_and_no_rehearsal_text(): void
    {
        $fixture = $this->retiredRun();

        $this->artisan('historic-import:purge-retired-run', $this->applyArgs($fixture['run']))->assertSuccessful();

        $run = $fixture['run']->fresh();
        $this->assertNotNull($run);
        $metadata = $run->processing_metadata?->toArray() ?? [];

        $this->assertSame([], array_values(array_diff(array_keys($metadata), RetiredRunPurge::KEPT_METADATA_KEYS)));
        $this->assertArrayHasKey('retirement', $metadata);
        $this->assertArrayHasKey('purge', $metadata);
        $this->assertSame($this->operation->operation_id, $metadata['historic_import']['operation_id']);
        $this->assertSame('2024-05-12-evening', $metadata['historic_import']['manifest_item_key']);
        $this->assertArrayNotHasKey('editorial_facts', $metadata['historic_import']);
        $this->assertSame('abc', $metadata['transcript_content']['hash']);

        $purge = $metadata['purge'];
        foreach (['service_structure', 'speaker_identification', 'sermon_extraction_plan', 'service_artifacts', 'service_structure_ensemble', 'service_transcript_path'] as $key) {
            $this->assertContains($key, $purge['scrubbed_metadata_keys']);
            $this->assertArrayHasKey($key, $purge['scrubbed_metadata_hashes']);
        }
        $this->assertContains('historic_import.editorial_facts', $purge['scrubbed_metadata_fields']);
        $this->assertSame(1, $purge['step_messages_cleared']);

        $this->assertNull($run->getRawOriginal('ai_analysis'));
        $this->assertNull($run->error_message);
        $this->assertNull($run->getRawOriginal('rms_stats'));
        $this->assertNull($metadata['retirement']['sermon']['title']);
        $this->assertNull($metadata['retirement']['sermon']['slug']);

        $step = SermonProcessingStep::query()->where('processing_id', $run->processing_id)->first();
        $this->assertNotNull($step, 'The step rows stay.');
        $this->assertNull($step->message);

        $stored = json_encode([$run->getAttributes(), $step->getAttributes()], JSON_UNESCAPED_UNICODE) ?: '';
        $this->assertStringNotContainsString('Rehearsal', $stored);
        $this->assertStringNotContainsString($fixture['section']->title, $stored);
    }
}
