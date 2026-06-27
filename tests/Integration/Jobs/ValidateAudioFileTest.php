<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Jobs\ValidateAudioFile;
use App\Models\MediaProcessingLog;
use App\Services\Media\Audio\AudioExtractionService;
use App\Services\Processing\MediaProcessingRunTransitionService;
use App\Services\Processing\StorageAdapterHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ValidateAudioFileTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_validates_a_valid_audio_file_on_local_disk(): void
    {
        config(['media-processing.storage.sermon_disk' => 'local']);
        config(['filesystems.disks.local.driver' => 'local']);

        // Create a real temp audio file
        $tempDir = storage_path('app/sermons/test');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $filename = uniqid().'.mp3';
        $filePath = "sermons/test/{$filename}";
        $absolutePath = $tempDir.'/'.$filename;

        // Write ID3 header to make mime_content_type return audio/mpeg
        $id3Header = "ID3\x04\x00\x00\x00\x00\x00\x00";
        file_put_contents($absolutePath, $id3Header.str_repeat("\xFF\xFB\x90\x00", 256));

        $log = MediaProcessingLog::factory()->audio()->pending()->create([
            'stored_file_path' => $filePath,
            'original_filename' => 'sermon-2026-01-05.mp3',
        ]);

        $mockExtractor = $this->createMock(AudioExtractionService::class);
        $mockExtractor->expects($this->once())
            ->method('validateAudioFile')
            ->with($this->anything());

        Log::shouldReceive('info')->atLeast()->once();

        $job = new ValidateAudioFile($log);
        $job->handle($mockExtractor, app(StorageAdapterHelper::class));

        $log->refresh();
        $this->assertEquals('audio_validation_complete', $log->current_step);
        $this->assertEquals('processing', $log->status->value);

        @unlink($absolutePath);
    }

    #[Test]
    public function it_fails_when_stored_file_path_is_missing(): void
    {
        $log = MediaProcessingLog::factory()->audio()->pending()->create([
            'stored_file_path' => null,
        ]);

        $mockExtractor = $this->createMock(AudioExtractionService::class);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->once();

        $job = new ValidateAudioFile($log);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No stored file path found in processing log');

        $job->handle($mockExtractor, app(StorageAdapterHelper::class));
    }

    #[Test]
    public function it_fails_when_file_does_not_exist_on_local_disk(): void
    {
        config(['media-processing.storage.sermon_disk' => 'local']);
        config(['filesystems.disks.local.driver' => 'local']);

        $log = MediaProcessingLog::factory()->audio()->pending()->create([
            'stored_file_path' => 'sermons/nonexistent-file.mp3',
            'original_filename' => 'test.mp3',
        ]);

        $mockExtractor = $this->createMock(AudioExtractionService::class);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->once();

        $job = new ValidateAudioFile($log);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Audio file not found at path');

        $job->handle($mockExtractor, app(StorageAdapterHelper::class));
    }

    #[Test]
    public function it_fails_when_audio_validation_service_throws(): void
    {
        config(['media-processing.storage.sermon_disk' => 'local']);
        config(['filesystems.disks.local.driver' => 'local']);

        // Create a real temp file
        $tempDir = storage_path('app/sermons/test');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $filename = uniqid().'.mp3';
        $absolutePath = $tempDir.'/'.$filename;
        $id3Header = "ID3\x04\x00\x00\x00\x00\x00\x00";
        file_put_contents($absolutePath, $id3Header.str_repeat("\xFF\xFB\x90\x00", 256));

        $log = MediaProcessingLog::factory()->audio()->pending()->create([
            'stored_file_path' => "sermons/test/{$filename}",
            'original_filename' => 'sermon.mp3',
        ]);

        $mockExtractor = $this->createMock(AudioExtractionService::class);
        $mockExtractor->expects($this->once())
            ->method('validateAudioFile')
            ->willThrowException(new \Exception('Audio file is too short'));

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->once();

        $job = new ValidateAudioFile($log);

        try {
            $job->handle($mockExtractor, app(StorageAdapterHelper::class));
            $this->fail('Expected exception was not thrown');
        } catch (\Exception $e) {
            $this->assertEquals('Audio file is too short', $e->getMessage());
        } finally {
            @unlink($absolutePath);
        }

        $log->refresh();
        $this->assertEquals('failed', $log->status->value);
        $this->assertStringContainsString('Audio validation failed', $log->error_message);
    }

    #[Test]
    public function it_downloads_from_s3_for_s3_disks(): void
    {
        config(['media-processing.storage.sermon_disk' => 's3test']);
        config(['filesystems.disks.s3test' => ['driver' => 's3']]);

        $s3Path = 'sermons/remote-audio.mp3';

        // Fake the S3 disk and local disk
        Storage::fake('s3test');
        Storage::fake('local');

        // Put file on fake S3
        $id3Header = "ID3\x04\x00\x00\x00\x00\x00\x00";
        Storage::disk('s3test')->put($s3Path, $id3Header.str_repeat("\xFF\xFB\x90\x00", 256));

        $log = MediaProcessingLog::factory()->audio()->pending()->create([
            'stored_file_path' => $s3Path,
            'original_filename' => 'remote-audio.mp3',
        ]);

        $mockExtractor = $this->createMock(AudioExtractionService::class);
        $mockExtractor->expects($this->once())
            ->method('validateAudioFile')
            ->with($this->anything());

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $job = new ValidateAudioFile($log);
        $job->handle($mockExtractor, app(StorageAdapterHelper::class));

        $log->refresh();
        $this->assertEquals('audio_validation_complete', $log->current_step);

        // Temp file should have been cleaned up in finally block
        $this->assertFalse(
            Storage::disk('local')->exists('temp/audio-validation/remote-audio.mp3')
        );
    }

    #[Test]
    public function it_fails_when_s3_file_not_found(): void
    {
        config(['media-processing.storage.sermon_disk' => 's3test']);
        config(['filesystems.disks.s3test' => ['driver' => 's3']]);

        Storage::fake('s3test');

        $log = MediaProcessingLog::factory()->audio()->pending()->create([
            'stored_file_path' => 'sermons/missing.mp3',
            'original_filename' => 'missing.mp3',
        ]);

        $mockExtractor = $this->createMock(AudioExtractionService::class);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->once();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $job = new ValidateAudioFile($log);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Audio file not found in S3 storage');

        $job->handle($mockExtractor, app(StorageAdapterHelper::class));
    }

    #[Test]
    public function failed_method_updates_processing_log(): void
    {
        $log = MediaProcessingLog::factory()->audio()->pending()->create();

        $job = new ValidateAudioFile($log);
        $job->failed(new \Exception('Something went wrong'));

        $log->refresh();
        $this->assertEquals('failed', $log->status->value);
        $this->assertEquals('Audio validation job failed: Something went wrong', $log->error_message);
    }

    #[Test]
    public function it_skips_all_work_when_processing_is_cancelled(): void
    {
        $log = MediaProcessingLog::factory()->audio()->cancelled()->create([
            'stored_file_path' => 'sermons/some-audio.mp3',
            'original_filename' => 'sermon.mp3',
        ]);

        $mockExtractor = $this->createMock(AudioExtractionService::class);
        $mockExtractor->expects($this->never())->method('validateAudioFile');

        Log::shouldReceive('info')->once()->withArgs(fn ($msg) => str_contains($msg, 'job skipped: processing cancelled'));

        $job = new ValidateAudioFile($log);
        $job->handle($mockExtractor, app(StorageAdapterHelper::class));
    }

    #[Test]
    public function mark_as_failed_does_not_overwrite_a_cancelled_run(): void
    {
        $log = MediaProcessingLog::factory()->audio()->cancelled()->create();

        $result = app(MediaProcessingRunTransitionService::class)
            ->markAsFailed($log, 'Audio validation failed: something went wrong');

        $this->assertFalse($result);
        $log->refresh();
        $this->assertEquals('cancelled', $log->status->value, 'markAsFailed must not overwrite a cancelled run');
    }

    #[Test]
    public function cancelled_run_is_not_overwritten_to_failed_by_failed_method(): void
    {
        $log = MediaProcessingLog::factory()->audio()->cancelled()->create();

        $job = new ValidateAudioFile($log);
        $job->failed(new \Exception('Queue exhausted retries'));

        $log->refresh();
        $this->assertEquals('cancelled', $log->status->value, 'Cancelled run must not be overwritten to failed by failed()');
    }
}
