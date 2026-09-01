<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\HistoricMedia\HistoricStagingGuard;
use App\Services\Media\ExtractedMediaDurationProbe;
use App\Services\Media\Video\VideoExtractionService;
use App\Services\Processing\StorageAdapterHelper;
use FFMpeg\FFProbe;
use FFMpeg\FFProbe\DataMapping\Format;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class RegenerateHistoricSermonAudioCommandTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    private string $batchRoot;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'media-processing.storage.historic_staging_disk' => 'historic_staging',
            'media-processing.storage.sermon_disk' => 'historic_staging',
            'media-processing.storage.transcript_disk' => 'historic_staging',
        ]);

        Storage::fake('historic_staging');
        $this->bindProbe(2528.988);
    }

    #[Test]
    public function it_is_dry_run_by_default(): void
    {
        [$operation, $log] = $this->pilotRunWithMissingAudio();
        $this->expectNoAudioExtraction();

        $this->artisan('historic-import:regenerate-sermon-audio', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
        ])
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();
    }

    #[Test]
    public function it_requires_confirmation_before_apply(): void
    {
        [$operation, $log] = $this->pilotRunWithMissingAudio();
        $this->expectNoAudioExtraction();

        $this->artisan('historic-import:regenerate-sermon-audio', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
            '--apply' => true,
        ])
            ->expectsOutputToContain('--apply requires --yes')
            ->assertFailed();
    }

    #[Test]
    public function it_regenerates_audio_beside_the_surviving_video_under_the_batch_root(): void
    {
        [$operation, $log] = $this->pilotRunWithMissingAudio();
        $audioPath = $this->batchRoot.'/sermons/audio/'.$log->processing_id.'_sermon.mp3';

        $this->fakeAudioExtractionWriting($audioPath);

        $this->artisan('historic-import:regenerate-sermon-audio', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
            '--apply' => true,
            '--yes' => true,
        ])
            ->expectsOutputToContain('Regenerated 1 sermon audio file')
            ->assertSuccessful();

        Storage::disk('historic_staging')->assertExists($audioPath);
    }

    #[Test]
    public function existing_audio_is_left_alone(): void
    {
        [$operation, $log] = $this->pilotRunWithMissingAudio();
        $audioPath = $this->batchRoot.'/sermons/audio/'.$log->processing_id.'_sermon.mp3';
        Storage::disk('historic_staging')->put($audioPath, 'original-audio');

        $this->expectNoAudioExtraction();

        $this->artisan('historic-import:regenerate-sermon-audio', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
            '--apply' => true,
            '--yes' => true,
        ])
            ->expectsOutputToContain('already present: 1')
            ->assertSuccessful();

        self::assertSame('original-audio', Storage::disk('historic_staging')->get($audioPath));
    }

    #[Test]
    public function it_refuses_a_run_whose_video_did_not_survive(): void
    {
        [$operation, $log] = $this->pilotRunWithMissingAudio();
        Storage::disk('historic_staging')->delete($this->batchRoot.'/sermons/871/video.mp4');

        $this->expectNoAudioExtraction();

        $this->artisan('historic-import:regenerate-sermon-audio', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
            '--apply' => true,
            '--yes' => true,
        ])
            ->expectsOutputToContain('video is missing from disk')
            ->assertFailed();
    }

    #[Test]
    public function it_refuses_a_run_owned_by_another_operation(): void
    {
        [, $log] = $this->pilotRunWithMissingAudio();
        $otherOperation = $this->createHistoricImportOperation();

        $this->expectNoAudioExtraction();

        $this->artisan('historic-import:regenerate-sermon-audio', [
            '--operation' => $otherOperation->operation_id,
            '--processing-id' => [$log->processing_id],
            '--apply' => true,
            '--yes' => true,
        ])
            ->expectsOutputToContain('is not owned by operation')
            ->assertFailed();
    }

    private function expectNoAudioExtraction(): void
    {
        $extractor = Mockery::mock(VideoExtractionService::class);
        $extractor->shouldNotReceive('extractOptimizedAudio');
        $this->app->instance(VideoExtractionService::class, $extractor);
    }

    private function fakeAudioExtractionWriting(string $audioPath): void
    {
        $extractor = Mockery::mock(VideoExtractionService::class);
        $extractor->shouldReceive('extractOptimizedAudio')
            ->once()
            ->andReturnUsing(function () use ($audioPath): array {
                Storage::disk('historic_staging')->put($audioPath, 'regenerated-audio');

                return ['audio_path' => $audioPath, 'full_path' => $audioPath];
            });
        $this->app->instance(VideoExtractionService::class, $extractor);
    }

    private function bindProbe(float $duration): void
    {
        $format = $this->createStub(Format::class);
        $format->method('get')->willReturn($duration);
        $ffprobe = $this->createStub(FFProbe::class);
        $ffprobe->method('format')->willReturn($format);

        $this->app->bind(
            ExtractedMediaDurationProbe::class,
            fn (): ExtractedMediaDurationProbe => new ExtractedMediaDurationProbe(
                app(StorageAdapterHelper::class),
                $ffprobe,
            ),
        );
    }

    /** @return array{0: HistoricImportOperation, 1: MediaProcessingLog} */
    private function pilotRunWithMissingAudio(): array
    {
        $operation = $this->createHistoricImportOperation();
        $stagingContext = app(HistoricStagingGuard::class)->contextForApprovedPlan(
            str_repeat('a', 64),
            str_repeat('b', 64),
        );
        $this->batchRoot = $stagingContext->batchRoot;

        $log = MediaProcessingLog::factory()->livestream()->completed()->create([
            'historic_import_operation_id' => $operation->id,
            'processing_metadata' => [
                'historic_import' => [
                    'job_key' => 'audio-regeneration-job',
                    'operation_id' => $operation->operation_id,
                    'staging_context' => $stagingContext->toArray(),
                ],
            ],
        ]);
        $sermon = Sermon::factory()->fromLivestream()->create([
            'livestream_processing_id' => $log->processing_id,
            'historic_import_operation_id' => $operation->id,
            'asset_disk' => null,
            'video_file_path' => 'sermons/871/video.mp4',
            'audio_file_path' => 'sermons/audio/'.$log->processing_id.'_sermon.mp3',
        ]);
        $log->forceFill(['sermon_id' => $sermon->id])->save();

        Storage::disk('historic_staging')->put($this->batchRoot.'/sermons/871/video.mp4', 'surviving-video');

        return [$operation, $log];
    }
}
