<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ContentHoldCheck;
use App\Data\HistoricStagingContext;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ScripturePassage;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Models\SongVideo;
use App\Services\HistoricMedia\HistoricRerunWatch;
use App\Support\ServiceArtifactDisk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WatchHistoricRerunCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = 'rerun-watch-test-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists(storage_path('app/private/'.$this->directory));

        foreach ([...HistoricRerunWatch::PROBED_DISKS, ServiceArtifactDisk::name()] as $disk) {
            Storage::fake($disk);
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/private/'.$this->directory));

        parent::tearDown();
    }

    #[Test]
    public function a_quiet_pass_writes_its_progress_and_pauses_nothing(): void
    {
        $run = $this->processingRun();
        $this->section($run, ServiceSectionType::Sermon, 600, 2400);
        $this->snapshot([$run->id]);

        $this->watch()->expectsOutputToContain('no stop')->assertSuccessful();

        $status = $this->file('watch-status.json');
        self::assertSame([], $status['stops']);
        self::assertSame(1, $status['progress']['members']);
        self::assertSame(1, $status['progress']['runs']['not_started']);
        self::assertFileDoesNotExist($this->absolute('watch-stop.json'));
        $this->assertNothingPaused();
    }

    #[Test]
    public function a_song_video_becoming_public_pauses_every_queue(): void
    {
        $run = $this->processingRun();
        $section = $this->section($run, ServiceSectionType::Song, 100, 300);
        $video = SongVideo::factory()->quarantined()->create(['service_section_id' => $section->id, 'asset_disk' => null]);
        $this->snapshot([$run->id]);

        $video->update(['publication_state' => 'published']);

        $this->watch()->expectsOutputToContain('became public')->assertFailed();

        $stop = $this->file('watch-stop.json');
        self::assertNotEmpty($stop['paused']);
        foreach (app(HistoricRerunWatch::class)->queues() as $queue) {
            self::assertTrue(Queue::isPaused(config('queue.default'), $queue), "{$queue} was not paused");
        }
    }

    #[Test]
    public function a_live_hold_that_is_gone_stops_the_pass(): void
    {
        $run = $this->processingRun();
        $section = $this->section($run, ServiceSectionType::Song, 100, 300, holds: [$this->hold('Looped text')]);
        $this->snapshot([$run->id]);

        $section->update(['metadata' => ['review_flags' => ['content_defect_hold']]]);

        $this->watch()->expectsOutputToContain('is gone')->assertFailed();
    }

    #[Test]
    public function a_held_run_is_progress_not_a_stop(): void
    {
        // Canary 15's diff flagged four held runs as "failed": containment working, not a fault.
        $run = $this->processingRun();
        $this->section($run, ServiceSectionType::Sermon, 600, 2400);
        $this->snapshot([$run->id]);

        $run->putCorpusRerunStamp(['grounds' => 'corpus_rerun', 'git_commit' => $this->snapshotCommit(), 'code_revision' => $this->snapshotRevision(), 'dispatched_at' => now()->toIso8601String()]);
        $run->forceFill(['status' => 'failed'])->save();

        $this->watch()->assertSuccessful();

        $status = $this->file('watch-status.json');
        self::assertSame(1, $status['progress']['runs']['held_or_failed']);
        self::assertNotEmpty($status['warnings']);
        $this->assertNothingPaused();
    }

    #[Test]
    public function a_job_that_fails_after_the_watch_began_stops_the_pass_and_an_earlier_one_does_not(): void
    {
        $run = $this->processingRun();
        $this->snapshot([$run->id]);
        $this->failedJob(now()->subHour());

        $this->watch()->assertSuccessful();

        $this->travel(1)->minutes();
        $this->failedJob(now());

        $this->watch()->expectsOutputToContain('job failed on historic-orchestration')->assertFailed();
    }

    #[Test]
    public function a_published_file_that_goes_missing_stops_the_pass_but_one_missing_at_the_start_does_not(): void
    {
        Storage::fake('historic_quarantine');
        $run = $this->processingRun();
        $section = $this->section($run, ServiceSectionType::Song, 100, 300);
        $kept = SongVideo::factory()->quarantined()->create(['service_section_id' => $section->id, 'asset_disk' => 'historic_quarantine', 'video_file_path' => 'sermons/songs/1/kept.mp4']);
        $other = $this->section($run, ServiceSectionType::Song, 400, 600);
        SongVideo::factory()->quarantined()->create(['service_section_id' => $other->id, 'asset_disk' => 'historic_quarantine', 'video_file_path' => 'sermons/songs/1/never-there.mp4']);
        Storage::disk('historic_quarantine')->put($kept->video_file_path, 'video');
        $this->snapshot([$run->id]);

        $this->watch()->assertSuccessful();
        self::assertSame(1, $this->file('watch-status.json')['progress']['published_files_missing_at_start']);

        Storage::disk('historic_quarantine')->delete($kept->video_file_path);

        $this->watch()->expectsOutputToContain('kept.mp4')->assertFailed();
        self::assertStringNotContainsString('never-there', implode("\n", $this->file('watch-stop.json')['stops']));
    }

    #[Test]
    public function a_staging_file_is_looked_for_under_its_runs_batch_root(): void
    {
        // The staging disk resolves a held run's files only under its batch root; read from the
        // disk root, live files look missing (8 song videos of canary runs on 2026-10-10).
        $plan = str_repeat('b', 64);
        $run = $this->processingRun();
        $run->writeProcessingMetadata(static fn (array $metadata): array => [...$metadata, 'historic_import' => ['staging_context' => (new HistoricStagingContext(
            manifestHash: str_repeat('a', 64),
            planHash: $plan,
            stagingDisk: 'historic_staging',
            batchRoot: "historic-batches/{$plan}",
            storageIdentity: ['driver' => 'local', 'bucket' => null, 'root_fingerprint' => 'r', 'prefix_fingerprint' => 'p'],
        ))->toArray()]]);
        $section = $this->section($run, ServiceSectionType::Song, 100, 300);
        $video = SongVideo::factory()->quarantined()->create(['service_section_id' => $section->id, 'asset_disk' => 'historic_staging', 'video_file_path' => 'sermons/songs/1/held.mp4']);
        Storage::disk('historic_staging')->put("historic-batches/{$plan}/".$video->video_file_path, 'video');
        $this->snapshot([$run->id]);

        $this->watch()->assertSuccessful();
        self::assertSame(0, $this->file('watch-status.json')['progress']['published_files_missing_at_start']);

        Storage::disk('historic_staging')->delete("historic-batches/{$plan}/".$video->video_file_path);

        $this->watch()->expectsOutputToContain('held.mp4')->assertFailed();
    }

    #[Test]
    public function lost_draws_stop_the_pass_only_above_the_bar_once_enough_ensembles_have_run(): void
    {
        $run = $this->processingRun();
        $this->snapshot([$run->id]);
        $this->watch()->assertSuccessful();
        $this->travel(1)->minutes();

        $this->ensembles($run, total: 9, losing: 2);
        $this->watch()->assertSuccessful();

        $this->ensembles($run, total: 10, losing: 2);
        $this->watch()->expectsOutputToContain('2 of 10 ensembles lost a draw')->assertFailed();
    }

    #[Test]
    public function speaker_identification_enabled_stops_the_pass(): void
    {
        $run = $this->processingRun();
        $this->snapshot([$run->id]);
        config(['media-processing.speaker_identification.enabled' => true]);

        $this->watch()->expectsOutputToContain('speaker identification is enabled')->assertFailed();
    }

    #[Test]
    public function an_unwritable_historic_disk_stops_the_pass(): void
    {
        $run = $this->processingRun();
        $this->snapshot([$run->id]);
        config(['filesystems.disks.historic_temp' => ['driver' => 'local', 'root' => '/dev/null/unreachable', 'throw' => true]]);
        Storage::forgetDisk('historic_temp');

        $this->watch()->expectsOutputToContain('historic_temp')->assertFailed();
    }

    #[Test]
    public function a_listening_verdict_stops_the_pass_through_the_same_door(): void
    {
        // Wrong on listening is a stopping condition only the operator can raise; the /loop
        // session passes it here so it pauses the same queues and leaves the same record.
        $run = $this->processingRun();
        $this->snapshot([$run->id]);

        $this->artisan('historic-import:rerun-watch', ['snapshot' => $this->path('before.json'), '--stop' => 'listening: 936-sermon-start clips_word'])
            ->expectsOutputToContain('936-sermon-start clips_word')
            ->assertFailed();

        self::assertSame(['listening: 936-sermon-start clips_word'], $this->file('watch-stop.json')['stops']);
        foreach (app(HistoricRerunWatch::class)->queues() as $queue) {
            self::assertTrue(Queue::isPaused(config('queue.default'), $queue), "{$queue} was not paused");
        }
    }

    #[Test]
    public function too_little_internal_space_stops_the_pass(): void
    {
        $run = $this->processingRun();
        $this->snapshot([$run->id]);

        $this->artisan('historic-import:rerun-watch', ['snapshot' => $this->path('before.json'), '--once' => true, '--min-free-gb' => 1_000_000_000])
            ->expectsOutputToContain('internal disk has')
            ->assertFailed();
    }

    private function watch(): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('historic-import:rerun-watch', ['snapshot' => $this->path('before.json'), '--once' => true, '--min-free-gb' => 0]);
    }

    private function assertNothingPaused(): void
    {
        foreach (app(HistoricRerunWatch::class)->queues() as $queue) {
            self::assertFalse(Queue::isPaused(config('queue.default'), $queue), "{$queue} was paused");
        }
    }

    private function failedJob(\DateTimeInterface $at): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'redis',
            'queue' => 'historic-orchestration',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\AutoPublishServiceSection']),
            'exception' => "RuntimeException: test\n#0 trace",
            'failed_at' => $at,
        ]);
    }

    private function ensembles(MediaProcessingLog $run, int $total, int $losing): void
    {
        $evidence = [];
        for ($index = 0; $index < $total; $index++) {
            $evidence[] = [
                'attempt_id' => "attempt-{$index}",
                'started_at' => now()->toIso8601String(),
                'outcomes' => [
                    ['slot' => 0, 'status' => $index < $losing ? 'unavailable' : 'valid'],
                    ['slot' => 1, 'status' => 'valid'],
                    ['slot' => 2, 'status' => 'invalid'],
                    ['slot' => 3, 'status' => 'valid'],
                ],
            ];
        }

        $run->writeProcessingMetadata(static fn (array $metadata): array => [...$metadata, 'service_structure_ensemble' => $evidence]);
    }

    private function snapshotCommit(): ?string
    {
        return $this->file('before.json')['git_commit'] ?? null;
    }

    private function snapshotRevision(): ?string
    {
        return $this->file('before.json')['code_revision'] ?? null;
    }

    private function processingRun(): MediaProcessingLog
    {
        return MediaProcessingLog::factory()->livestream()->completed()->create([
            'sermon_id' => Sermon::factory()->create(['reference' => 'John 3:16', 'scripture_passage_id' => ScripturePassage::factory()])->id,
            'sermon_start_time' => 600.0,
            'sermon_end_time' => 2400.0,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $holds
     */
    private function section(MediaProcessingLog $run, ServiceSectionType $type, float $start, float $end, array $holds = []): ServiceSection
    {
        return ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_type' => $type,
            'section_order' => (int) $start,
            'title' => ucfirst($type->value),
            'start_time' => $start,
            'end_time' => $end,
            'duration' => $end - $start,
            'metadata' => $holds === [] ? [] : ['content_holds' => $holds, 'review_flags' => ['content_defect_hold']],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function hold(string $reason): array
    {
        return [
            'reason' => $reason,
            'evidence' => 'test',
            'found_by' => ContentHoldCheck::LoopScreen->value,
            'start_time' => 100,
            'end_time' => 300,
            'held_at' => '2026-09-24T00:00:00+00:00',
        ];
    }

    /**
     * @param  list<int>  $runIds
     */
    private function snapshot(array $runIds): void
    {
        $this->artisan('historic-import:rerun-snapshot', ['runs' => $runIds, '--output' => $this->path('before.json')])
            ->assertSuccessful();
    }

    private function path(string $name): string
    {
        return $this->directory.'/'.$name;
    }

    private function absolute(string $name): string
    {
        return storage_path('app/private/'.$this->path($name));
    }

    /**
     * @return array<string, mixed>
     */
    private function file(string $name): array
    {
        return json_decode((string) file_get_contents($this->absolute($name)), true);
    }
}
