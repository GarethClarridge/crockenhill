<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Jobs\ClassifyServiceAudio;
use App\Jobs\DetectServiceStructure;
use App\Jobs\TranscribeFullService;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricProcessingThroughput;
use App\Services\Media\Audio\AudioClassifier;
use App\Services\Media\Audio\AudioTimeline;
use App\Services\Media\Audio\RmsAnalysisService;
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Services\Processing\ProcessingArtifactReuse;
use App\Services\Processing\ProcessingPipelineBuilder;
use App\Services\Processing\StorageAdapterHelper;
use App\Support\ServiceArtifactDisk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\AudioTimelineFixture;
use Tests\TestCase;

class ClassifyServiceAudioTest extends TestCase
{
    use RefreshDatabase;

    private const string AUDIO_PATH = 'service-audio/2021-10-10/morning-run.mp3';

    private const string AUDIO_BYTES = 'compressed service audio';

    private const string RMS_PATH = 'service-transcripts/2021-10-10/morning-run.rms.json';

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

        Storage::disk('sermons')->put(self::AUDIO_PATH, self::AUDIO_BYTES);
        Storage::disk('durable')->put(self::RMS_PATH, $this->rmsLog(60.0));
    }

    #[Test]
    public function it_classifies_the_archived_audio_and_records_the_timeline_on_the_durable_disk(): void
    {
        $this->fakeClassifier(AudioTimelineFixture::json([[0, 30, 0.9, 0.1]], 60.0, hash('sha256', self::AUDIO_BYTES)));
        $log = $this->processingRun();

        $this->dispatch($log);

        $log->refresh();
        $this->assertIsString($log->audio_timeline_path);
        $this->assertStringStartsWith(ServiceArtifactDisk::DURABLE_PREFIX, $log->audio_timeline_path);
        $this->assertStringEndsWith('.classes.json', $log->audio_timeline_path);
        $this->assertSame('durable', ServiceArtifactDisk::for($log->audio_timeline_path));
        Storage::disk('durable')->assertExists($log->audio_timeline_path);
        Storage::disk('temp')->assertMissing($log->audio_timeline_path);

        $timeline = AudioTimeline::fromJson((string) Storage::disk('durable')->get($log->audio_timeline_path));
        $this->assertSame(AudioTimelineFixture::MODEL_REVISION, $timeline->modelRevision);
        $this->assertSame(60.0, $timeline->audioSeconds);

        $recorded = collect(ServiceArtifactStorage::recordedFor($log))->firstWhere('kind', ClassifyServiceAudio::ARTIFACT_KIND);
        $this->assertSame($log->audio_timeline_path, $recorded['path'] ?? null);

        Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
            && str_ends_with($process->command[1], 'scripts/classify_audio.py')
            && $process->command[2] === Storage::disk('sermons')->path(self::AUDIO_PATH));
    }

    #[Test]
    public function it_fails_a_run_with_no_archived_audio_with_a_reason(): void
    {
        Process::fake();
        $log = $this->processingRun(withAudio: false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No archived service audio is recorded for this run');

        try {
            $this->dispatch($log);
        } finally {
            Process::assertNothingRan();
            $this->assertNull($log->fresh()?->audio_timeline_path);
        }
    }

    #[Test]
    public function it_refuses_a_timeline_whose_duration_disagrees_with_the_rms_log(): void
    {
        $this->fakeClassifier(AudioTimelineFixture::json([], 90.0, hash('sha256', self::AUDIO_BYTES)));
        $log = $this->processingRun();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('they do not describe the same recording');

        try {
            $this->dispatch($log);
        } finally {
            $this->assertNull($log->fresh()?->audio_timeline_path);
            $this->assertSame([], Storage::disk('durable')->files('service-transcripts/unknown-date'));
        }
    }

    #[Test]
    public function it_fails_when_the_classifier_exits_non_zero_and_writes_nothing(): void
    {
        Process::fake(['*' => Process::result(output: '', errorOutput: 'Missing dependency: transformers', exitCode: 1)]);
        $log = $this->processingRun();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Audio classifier failed: Missing dependency: transformers');

        try {
            $this->dispatch($log);
        } finally {
            $this->assertNull($log->fresh()?->audio_timeline_path);
        }
    }

    #[Test]
    public function it_fails_when_the_classifier_output_is_not_json(): void
    {
        $this->fakeClassifier('Loading weights... done');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a JSON object');

        $this->dispatch($this->processingRun());
    }

    #[Test]
    public function it_fails_when_the_classifier_output_is_a_short_timeline(): void
    {
        $payload = AudioTimelineFixture::payload([], 60.0, hash('sha256', self::AUDIO_BYTES));
        $payload['windows'] = array_slice($payload['windows'], 0, 4);
        $this->fakeClassifier((string) json_encode($payload));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid timeline: Audio timeline covers 20.0s of 60.0s');

        $this->dispatch($this->processingRun());
    }

    #[Test]
    public function it_fails_when_the_classifier_reports_a_different_input(): void
    {
        $this->fakeClassifier(AudioTimelineFixture::json([], 60.0, hash('sha256', 'some other file')));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('different input hash');

        $this->dispatch($this->processingRun());
    }

    #[Test]
    public function it_reuses_a_recorded_timeline_on_resume_without_reclassifying(): void
    {
        Process::fake();
        $path = 'service-transcripts/2021-10-10/morning-run.classes.json';
        Storage::disk('durable')->put($path, AudioTimelineFixture::json([], 60.0));
        $log = $this->processingRun(['audio_timeline_path' => $path]);

        $this->dispatch($log, resuming: true);

        Process::assertNothingRan();
        $this->assertSame($path, $log->fresh()?->audio_timeline_path);
    }

    #[Test]
    public function it_reclassifies_a_recorded_timeline_on_a_fresh_run(): void
    {
        $this->fakeClassifier(AudioTimelineFixture::json([], 60.0, hash('sha256', self::AUDIO_BYTES)));
        $path = 'service-transcripts/2021-10-10/morning-run.classes.json';
        Storage::disk('durable')->put($path, AudioTimelineFixture::json([], 60.0));

        $this->dispatch($this->processingRun(['audio_timeline_path' => $path]));

        Process::assertRanTimes(fn (): bool => true, 1);
    }

    #[Test]
    public function both_pipelines_that_detect_structure_classify_between_transcription_and_detection(): void
    {
        $builder = app(ProcessingPipelineBuilder::class);
        $log = new MediaProcessingLog;

        foreach ([$builder->buildLivestreamChainJobs($log), $builder->buildAutoTrimVideoPipeline($log)] as $jobs) {
            $classes = array_map(static fn (object $job): string => $job::class, $jobs);
            $classify = array_search(ClassifyServiceAudio::class, $classes, true);

            $this->assertIsInt($classify);
            $this->assertSame(TranscribeFullService::class, $classes[$classify - 1]);
            $this->assertSame(DetectServiceStructure::class, $classes[$classify + 1]);
        }
    }

    #[Test]
    public function historic_runs_classify_on_the_cpu_pool_not_the_whisper_pool(): void
    {
        $throughput = app(HistoricProcessingThroughput::class);

        $this->assertSame(
            $throughput->queueForClass(\App\Jobs\GenerateRmsLog::class),
            $throughput->queueForClass(ClassifyServiceAudio::class),
        );
        $this->assertNotSame(
            $throughput->queueForClass(TranscribeFullService::class),
            $throughput->queueForClass(ClassifyServiceAudio::class),
        );
    }

    private function fakeClassifier(string $output): void
    {
        Process::fake(['*' => Process::result(output: $output)]);
    }

    private function dispatch(MediaProcessingLog $log, bool $resuming = false): void
    {
        (new ClassifyServiceAudio($log, $resuming))->handle(
            app(AudioClassifier::class),
            app(ProcessingArtifactReuse::class),
            app(RmsAnalysisService::class),
            app(ServiceArtifactStorage::class),
            app(StorageAdapterHelper::class),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function processingRun(array $attributes = [], bool $withAudio = true): MediaProcessingLog
    {
        $artifacts = [['kind' => 'rms', 'disk' => 'durable', 'path' => self::RMS_PATH]];

        if ($withAudio) {
            $artifacts[] = ['kind' => 'audio', 'disk' => 'sermons', 'path' => self::AUDIO_PATH];
        }

        return MediaProcessingLog::factory()->livestream()->processing()->create([
            'rms_log_path' => self::RMS_PATH,
            'processing_metadata' => [ServiceArtifactStorage::METADATA_KEY => $artifacts],
            ...$attributes,
        ]);
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
