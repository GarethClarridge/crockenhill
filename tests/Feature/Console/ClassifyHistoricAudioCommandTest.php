<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Actions\BackfillAudioTimeline;
use App\Models\MediaProcessingLog;
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Services\Media\ExtractedMediaDurationProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AudioTimelineFixture;
use Tests\TestCase;

class ClassifyHistoricAudioCommandTest extends TestCase
{
    use RefreshDatabase;

    private const string AUDIO_BYTES = 'compressed service audio';

    /** @var array<string, float> Probed duration by absolute path */
    private array $durations = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('temp');
        Storage::fake('durable');
        Storage::fake('sermons');
        config([
            'media-processing.storage.temp_disk' => 'temp',
            'media-processing.storage.transcript_disk' => 'durable',
            'media-processing.storage.sermon_disk' => 'sermons',
        ]);

        $probe = Mockery::mock(ExtractedMediaDurationProbe::class);
        $probe->shouldReceive('durationOf')->andReturnUsing(fn (string $path): float => $this->durations[$path] ?? 0.0);
        $this->app->instance(ExtractedMediaDurationProbe::class, $probe);
    }

    #[Test]
    public function the_dry_run_reports_what_each_run_needs_and_writes_nothing(): void
    {
        Process::fake();
        $recorded = $this->runWithAudio(recorded: true);
        $orphaned = $this->runWithAudio(recorded: false);
        $missing = $this->runWithAudio(recorded: false, file: false);
        $mismatched = $this->runWithAudio(recorded: true, duration: 90.0);
        $done = $this->runWithAudio(recorded: true, timeline: true);

        $this->artisan('historic-import:classify-audio', ['runs' => [$recorded->id, $orphaned->id, $missing->id, $mismatched->id, $done->id]])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('would classify durable:')
            ->expectsOutputToContain('would re-attach durable:')
            ->expectsOutputToContain('no audio artifact recorded; audio missing at durable:')
            ->expectsOutputToContain('audio lasts 90.0s but the RMS log ends at 60.0s')
            ->expectsOutputToContain('already has a usable audio timeline')
            ->expectsOutputToContain('1 classify, 1 done, 1 reattach, 2 refused')
            ->assertSuccessful();

        Process::assertNothingRan();
        $this->assertNull($orphaned->fresh()?->audio_timeline_path);
        $this->assertCount(1, ServiceArtifactStorage::recordedFor($orphaned->fresh() ?? $orphaned));
    }

    #[Test]
    public function it_re_attaches_orphaned_audio_then_classifies_exactly_that_file(): void
    {
        Process::fake(['*' => Process::result(output: AudioTimelineFixture::json([], 60.0, hash('sha256', self::AUDIO_BYTES)))]);
        $run = $this->runWithAudio(recorded: false);
        $audio = app(ServiceArtifactStorage::class)->audioLocation($run->processing_id);

        $this->artisan('historic-import:classify-audio', ['runs' => [$run->id], '--execute' => true])
            ->expectsOutputToContain('1 reattached')
            ->assertSuccessful();

        $run->refresh();
        $entry = collect($run->processing_metadata?->toArray()[ServiceArtifactStorage::METADATA_KEY] ?? [])->firstWhere('kind', 'audio');
        $this->assertSame(['durable', $audio['path'], BackfillAudioTimeline::REATTACHED_BY], [$entry['disk'] ?? null, $entry['path'] ?? null, $entry['reattached_by'] ?? null]);
        $this->assertIsString($run->audio_timeline_path);
        Storage::disk('durable')->assertExists($run->audio_timeline_path);
        Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
            && $process->command[2] === Storage::disk('durable')->path($audio['path']));
    }

    #[Test]
    public function it_never_classifies_a_refused_run(): void
    {
        Process::fake();
        $run = $this->runWithAudio(recorded: false, duration: 3000.0);

        $this->artisan('historic-import:classify-audio', ['runs' => [$run->id], '--execute' => true])
            ->expectsOutputToContain('not the same recording')
            ->assertSuccessful();

        Process::assertNothingRan();
        $this->assertCount(1, ServiceArtifactStorage::recordedFor($run->fresh() ?? $run), 'Nothing is re-attached for a refused run.');
    }

    #[Test]
    public function it_stops_at_max_runs_classified(): void
    {
        Process::fake(['*' => Process::result(output: AudioTimelineFixture::json([], 60.0, hash('sha256', self::AUDIO_BYTES)))]);
        $first = $this->runWithAudio(recorded: true);
        $second = $this->runWithAudio(recorded: true);

        $this->artisan('historic-import:classify-audio', ['runs' => [$first->id, $second->id], '--execute' => true, '--max' => 1])
            ->expectsOutputToContain('1 classified, 1 not reached')
            ->assertSuccessful();

        $this->assertNull($second->fresh()?->audio_timeline_path);
    }

    private function runWithAudio(bool $recorded, bool $file = true, float $duration = 60.0, bool $timeline = false): MediaProcessingLog
    {
        $run = MediaProcessingLog::factory()->livestream()->completed()->create();
        $audio = app(ServiceArtifactStorage::class)->audioLocation($run->processing_id);
        $rmsPath = 'service-transcripts/rms-'.$run->processing_id.'.rms.json';
        Storage::disk('durable')->put($rmsPath, $this->rmsLog(60.0));

        if ($file) {
            Storage::disk('durable')->put($audio['path'], self::AUDIO_BYTES);
            $this->durations[Storage::disk('durable')->path($audio['path'])] = $duration;
        }

        $artifacts = [['kind' => 'rms', 'disk' => 'durable', 'path' => $rmsPath]];

        if ($recorded) {
            $artifacts[] = ['kind' => 'audio', 'disk' => $audio['disk'], 'path' => $audio['path']];
        }

        $run->forceFill([
            'rms_log_path' => $rmsPath,
            'processing_metadata' => [ServiceArtifactStorage::METADATA_KEY => $artifacts],
            'audio_timeline_path' => $timeline ? AudioTimelineFixture::put('durable', 'service-transcripts/'.$run->processing_id.'.classes.json', 60.0) : null,
        ])->save();

        return $run;
    }

    private function rmsLog(float $end): string
    {
        $lines = [];

        for ($tenth = 0; $tenth <= $end * 10; $tenth++) {
            $lines[] = sprintf('frame:%d pts:%d pts_time:%.1f', $tenth, $tenth * 800, $tenth / 10);
            $lines[] = 'lavfi.astats.Overall.RMS_level=-25.0';
        }

        return implode("\n", $lines)."\n";
    }
}
