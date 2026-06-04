<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\EnhanceAudio;
use App\Models\MediaProcessingLog;
use App\Services\Media\Audio\AudioEnhancementService;
use App\Services\StorageAdapterHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EnhanceAudioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        Config::set('media-processing.storage.sermon_disk', 'local');
        Config::set('media-processing.audio_enhancement.enabled', true);
    }

    // ---- Happy path ----

    #[Test]
    public function it_stores_enhanced_audio_path_and_marks_step_complete_on_success(): void
    {
        $audioPath = 'sermons/audio/2026/03/'.uniqid('test_').'_success.mp3';
        $log = MediaProcessingLog::factory()->audio()->pending()->create([
            'source_file_path' => $audioPath,
        ]);

        Storage::disk('local')->put($audioPath, 'audio-content');

        $enhancedPath = storage_path('app/temp/'.$log->processing_id.'_enhanced.mp3');

        $this->mock(AudioEnhancementService::class, function (MockInterface $mock) use ($enhancedPath): void {
            $mock->shouldReceive('enhance')
                ->once()
                ->andReturn($enhancedPath);
        });

        (new EnhanceAudio($log))->handle(
            app(AudioEnhancementService::class),
            app(StorageAdapterHelper::class)
        );

        $log->refresh();
        $this->assertSame($enhancedPath, $log->enhanced_audio_file_path);
        $this->assertSame('audio_enhancement_complete', $log->current_step);
    }

    // ---- Enhancement returns null (disabled or skipped) ----

    #[Test]
    public function it_marks_step_skipped_when_enhancement_service_returns_null(): void
    {
        $audioPath = 'sermons/audio/2026/03/'.uniqid('test_').'_null.mp3';
        $log = MediaProcessingLog::factory()->audio()->pending()->create([
            'source_file_path' => $audioPath,
        ]);

        Storage::disk('local')->put($audioPath, 'audio-content');

        $this->mock(AudioEnhancementService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('enhance')
                ->once()
                ->andReturn(null);
        });

        (new EnhanceAudio($log))->handle(
            app(AudioEnhancementService::class),
            app(StorageAdapterHelper::class)
        );

        $log->refresh();
        $this->assertNull($log->enhanced_audio_file_path);
        $this->assertSame('audio_enhancement_skipped', $log->current_step);
    }

    // ---- Enhancement throws — must not propagate ----

    #[Test]
    public function it_does_not_throw_when_enhancement_service_throws_and_marks_step_skipped(): void
    {
        $audioPath = 'sermons/audio/2026/03/'.uniqid('test_').'_throw.mp3';
        $log = MediaProcessingLog::factory()->audio()->pending()->create([
            'source_file_path' => $audioPath,
        ]);

        Storage::disk('local')->put($audioPath, 'audio-content');

        $this->mock(AudioEnhancementService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('enhance')
                ->once()
                ->andThrow(new \RuntimeException('FFmpeg failed'));
        });

        // Must not throw — pipeline should continue with original file
        (new EnhanceAudio($log))->handle(
            app(AudioEnhancementService::class),
            app(StorageAdapterHelper::class)
        );

        $log->refresh();
        $this->assertNull($log->enhanced_audio_file_path);
        $this->assertSame('audio_enhancement_skipped', $log->current_step);
    }

    // ---- Missing audio path ----

    #[Test]
    public function it_skips_when_no_audio_path_is_available(): void
    {
        $log = MediaProcessingLog::factory()->audio()->pending()->create([
            'source_file_path' => null,
        ]);

        $this->mock(AudioEnhancementService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('enhance');
        });

        (new EnhanceAudio($log))->handle(
            app(AudioEnhancementService::class),
            app(StorageAdapterHelper::class)
        );

        $log->refresh();
        $this->assertNull($log->enhanced_audio_file_path);
        $this->assertSame('audio_enhancement_skipped', $log->current_step);
    }

    // ---- Cancelled run ----

    #[Test]
    public function it_returns_early_when_processing_is_cancelled(): void
    {
        $log = MediaProcessingLog::factory()->audio()->cancelled()->create([
            'source_file_path' => 'sermons/audio/2026/03/test.mp3',
        ]);

        $this->mock(AudioEnhancementService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('enhance');
        });

        (new EnhanceAudio($log))->handle(
            app(AudioEnhancementService::class),
            app(StorageAdapterHelper::class)
        );

        $log->refresh();
        $this->assertNull($log->enhanced_audio_file_path);
    }
}
