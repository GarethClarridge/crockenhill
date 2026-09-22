<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Data\ChurchServiceTranscript;
use App\Data\HistoricStagingContext;
use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Services\Media\Audio\AudioChunkingService;
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Services\Media\Audio\ServiceTranscriptRedecoder;
use Closure;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class RedecodeServiceTranscriptsCommandTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use DatabaseTransactions;

    private const Source = 'temp/historic-source.webm';

    private const StoredTranscript = 'service-transcripts/2020-01-05/morning-stored.json';

    private string $outputDir;

    private int $compressions = 0;

    private bool $whisperFails = false;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        Config::set('media-processing.storage.temp_disk', 'local');
        Config::set('media-processing.storage.transcript_disk', 'local');
        Config::set('media-processing.storage.sermon_disk', 'public');
        Config::set('media-processing.transcription.local_whisper_url', 'http://whisper:8000');

        $this->outputDir = sys_get_temp_dir().'/redecode-test-'.bin2hex(random_bytes(4));
        mkdir($this->outputDir);

        $registry = Mockery::mock(HistoricStagingContextRegistry::class);
        $registry->shouldReceive('within')
            ->andReturnUsing(static fn (HistoricStagingContext $context, Closure $callback): mixed => $callback());
        $this->app->instance(HistoricStagingContextRegistry::class, $registry);

        $chunking = Mockery::mock(AudioChunkingService::class);
        $chunking->shouldReceive('compressAudioForTranscription')
            ->andReturnUsing(function (): string {
                $this->compressions++;
                $path = tempnam(sys_get_temp_dir(), 'redecode-audio-');
                file_put_contents($path, 'compressed audio');

                return $path;
            });
        $this->app->instance(AudioChunkingService::class, $chunking);

        Http::fake(fn () => $this->whisperFails
            ? Http::response('boom', 500)
            : Http::response([
                'duration' => 60.0,
                'segments' => [['id' => 0, 'start' => 0.0, 'end' => 30.0, 'text' => ' Good morning. ']],
            ]));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->outputDir);

        parent::tearDown();
    }

    #[Test]
    public function it_writes_a_private_artifact_bound_to_the_source_and_stored_transcript(): void
    {
        $run = $this->completedRun();

        $this->redecode([$run->id])->assertSuccessful();

        $path = $this->artifactPath($run);
        $artifact = json_decode((string) file_get_contents($path), true);

        self::assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
        self::assertSame(ServiceTranscriptRedecoder::SCHEMA, $artifact['schema']);
        self::assertSame(hash('sha256', 'source bytes'), $artifact['source']['sha256']);
        self::assertSame(hash('sha256', 'compressed audio'), $artifact['compressed_audio']['sha256']);
        self::assertSame(
            ServiceTranscriptRedecoder::transcriptHash($this->storedTranscript()),
            $artifact['stored_transcript']['sha256'],
        );
        self::assertSame([], $artifact['stored_transcript']['suspect_blocks']);
        self::assertSame('original', $artifact['stored_transcript']['stratum']);
        self::assertSame('0', $artifact['decode']['request']['max_context']);
        self::assertArrayHasKey('app/Services/Media/Audio/LocalWhisperDecoding.php', $artifact['decode']['code']);
        self::assertSame('Good morning.', $artifact['new_transcript']['cues'][0]['text']);
    }

    #[Test]
    public function it_records_a_recovered_stored_transcript_as_its_own_stratum(): void
    {
        $run = $this->completedRun();
        $metadata = $run->processing_metadata?->toArray() ?? [];
        $metadata['service_transcript_path'] = 'service-transcripts/2020-01-05/morning-stored.normalized-repetition-recovered.json';
        $run->update(['processing_metadata' => $metadata]);
        Storage::disk('local')->put($metadata['service_transcript_path'], (string) Storage::disk('local')->get(self::StoredTranscript));

        $this->redecode([$run->id])->assertSuccessful();

        $artifact = json_decode((string) file_get_contents($this->artifactPath($run)), true);
        self::assertSame('recovered', $artifact['stored_transcript']['stratum']);
    }

    #[Test]
    public function it_leaves_the_run_and_its_banked_artifacts_untouched(): void
    {
        $run = $this->completedRun();
        $metadataBefore = $run->fresh()?->processing_metadata?->toArray();

        $this->redecode([$run->id])->assertSuccessful();

        self::assertSame($metadataBefore, $run->fresh()?->processing_metadata?->toArray());
        self::assertSame([], ServiceArtifactStorage::recordedFor($run->fresh()));
        self::assertSame([], Storage::disk('public')->allFiles());
        self::assertSame(
            ServiceTranscriptRedecoder::transcriptHash($this->storedTranscript()),
            ServiceTranscriptRedecoder::transcriptHash(ChurchServiceTranscript::fromArray(
                json_decode((string) Storage::disk('local')->get(self::StoredTranscript), true),
            )),
        );
    }

    #[Test]
    public function it_resumes_by_skipping_runs_that_already_have_an_artifact(): void
    {
        $run = $this->completedRun();
        file_put_contents($this->artifactPath($run), 'earlier decode');

        $this->redecode([$run->id])
            ->expectsOutputToContain('artifact already exists')
            ->assertSuccessful();

        self::assertSame('earlier decode', file_get_contents($this->artifactPath($run)));
        self::assertSame(0, $this->compressions);
    }

    #[Test]
    public function it_counts_a_changed_source_as_unassessable_and_writes_nothing(): void
    {
        $run = $this->completedRun(attributes: ['file_hash' => str_repeat('f', 64)]);

        $this->assertUnassessable($run, 'hash does not match');
    }

    #[Test]
    public function it_counts_a_missing_source_as_unassessable(): void
    {
        $run = $this->completedRun(withSource: false);

        $this->assertUnassessable($run, 'staged source is missing');
    }

    #[Test]
    public function it_counts_a_missing_stored_transcript_as_unassessable(): void
    {
        $run = $this->completedRun();
        Storage::disk('local')->delete(self::StoredTranscript);

        $this->assertUnassessable($run, 'stored transcript is unavailable');
    }

    #[Test]
    public function it_refuses_a_stored_transcript_from_another_model(): void
    {
        $run = $this->completedRun(storedSource: ChurchServiceTranscript::SOURCE_WHISPER_API);

        $this->assertUnassessable($run, 'came from whisper_api');
    }

    #[Test]
    public function it_refuses_a_run_that_is_not_completed(): void
    {
        $run = $this->completedRun(attributes: ['status' => ProcessingStatus::Failed]);

        $this->assertUnassessable($run, 'is failed, not completed');
    }

    #[Test]
    public function it_refuses_a_run_without_a_staging_context(): void
    {
        $run = $this->completedRun(withStagingContext: false);

        $this->assertUnassessable($run, 'no historic staging context');
    }

    #[Test]
    public function it_counts_a_failed_decode_as_unassessable_and_removes_the_compressed_audio(): void
    {
        $run = $this->completedRun();
        $this->whisperFails = true;

        $this->assertUnassessable($run, 'decode failed');
        self::assertSame([], glob(sys_get_temp_dir().'/redecode-audio-*') ?: []);
    }

    #[Test]
    public function it_requires_an_existing_absolute_output_directory(): void
    {
        $this->artisan('service:redecode-transcripts', ['runs' => [1], '--output-dir' => 'relative'])
            ->expectsOutputToContain('--output-dir')
            ->assertFailed();
    }

    /** @param list<int> $runs */
    private function redecode(array $runs): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('service:redecode-transcripts', ['runs' => $runs, '--output-dir' => $this->outputDir]);
    }

    private function assertUnassessable(MediaProcessingLog $run, string $reason): void
    {
        $this->redecode([$run->id])
            ->expectsOutputToContain($reason)
            ->assertFailed();

        self::assertFileDoesNotExist($this->artifactPath($run));
    }

    private function artifactPath(MediaProcessingLog $run): string
    {
        return "{$this->outputDir}/run-{$run->id}.json";
    }

    private function storedTranscript(string $source = ChurchServiceTranscript::SOURCE_LOCAL_WHISPER): ChurchServiceTranscript
    {
        return ChurchServiceTranscript::fromCues(
            [['start' => 0.0, 'end' => 30.0, 'text' => 'Good morning good morning.']],
            60.0,
            $source,
        );
    }

    /** @param array<string, mixed> $attributes */
    private function completedRun(
        bool $withSource = true,
        bool $withStagingContext = true,
        string $storedSource = ChurchServiceTranscript::SOURCE_LOCAL_WHISPER,
        array $attributes = [],
    ): MediaProcessingLog {
        $operation = $this->createHistoricImportOperation();
        $metadata = [
            'historic_import' => ['operation_id' => $operation->operation_id],
            'service_transcript_path' => self::StoredTranscript,
            'service_transcript_suspect_blocks' => [],
        ];

        if ($withStagingContext) {
            $metadata['historic_import']['staging_context'] = (new HistoricStagingContext(
                manifestHash: str_repeat('a', 64),
                planHash: str_repeat('b', 64),
                stagingDisk: 'historic_staging',
                batchRoot: 'historic-batches/redecode',
                storageIdentity: [
                    'driver' => 'local',
                    'bucket' => null,
                    'root_fingerprint' => str_repeat('c', 64),
                    'prefix_fingerprint' => str_repeat('d', 64),
                ],
            ))->toArray();
        }

        Storage::disk('local')->put(
            self::StoredTranscript,
            json_encode($this->storedTranscript($storedSource)->toArray(), JSON_THROW_ON_ERROR),
        );

        if ($withSource) {
            Storage::disk('local')->put(self::Source, 'source bytes');
        }

        return MediaProcessingLog::factory()->livestream()->create([
            'historic_import_operation_id' => $operation->id,
            'status' => ProcessingStatus::Completed,
            'current_step' => 'completed',
            'source_file_path' => self::Source,
            'file_hash' => hash('sha256', 'source bytes'),
            'completed_at' => now(),
            'processing_metadata' => $metadata,
            ...$attributes,
        ]);
    }
}
