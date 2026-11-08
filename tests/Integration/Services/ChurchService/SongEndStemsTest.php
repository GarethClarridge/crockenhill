<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Models\MediaProcessingLog;
use App\Services\ChurchService\SongEndStems;
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Services\Processing\StorageAdapterHelper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * E29: each song end that fades into speech is separated once, around its cut, and banked: the
 * levels the ending is read from and the instrument track the clip plays.
 */
class SongEndStemsTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function a_cut_is_separated_once_and_its_levels_and_instruments_banked(): void
    {
        $log = $this->log();
        $calls = [];
        Process::fake(function (PendingProcess $process) use (&$calls) {
            $calls[] = $process->command;
            file_put_contents($process->command[5].'/instruments.flac', 'flac');

            return Process::result(json_encode(['model' => 'htdemucs', 'model_signature' => '955717e8', 'sample_rate' => 44100,
                'start' => 92.0, 'end' => 116.0, 'frame_seconds' => 0.1, 'voice_db' => [-24.0, -70.0], 'instruments_db' => [-20.0, -60.0],
                'instruments_path' => $process->command[5].'/instruments.flac', 'runtime' => ['seconds' => 13.0]], JSON_THROW_ON_ERROR));
        });
        $stems = new SongEndStems(app(ServiceArtifactStorage::class), $this->storage());

        $this->assertSame(1, $stems->prepare($log, [100.0])['separated']);
        $this->assertSame(0, $stems->prepare($log->fresh(), [100.0])['separated']);

        $this->assertCount(1, $calls);
        $this->assertSame(['python3', base_path('scripts/separate_song_end.py'), '/audio.mp3', '92.000', '116.000'], array_slice($calls[0], 0, 5));
        $banked = $stems->read($log->fresh(), 100.0);
        $this->assertNotNull($banked);
        $this->assertSame([92.0, 0.1, [-24.0, -70.0], [-20.0, -60.0]], [$banked['start'], $banked['frame_seconds'], $banked['voice_db'], $banked['instruments_db']]);
        $this->assertSame('flac', Storage::disk($banked['instruments']['disk'])->get($banked['instruments']['path']));
        $this->assertDirectoryDoesNotExist($calls[0][5]);
    }

    #[Test]
    public function a_failed_separation_banks_nothing(): void
    {
        $log = $this->log();
        Process::fake(fn () => Process::result(errorOutput: 'ffmpeg could not read', exitCode: 2));
        $stems = new SongEndStems(app(ServiceArtifactStorage::class), $this->storage());

        try {
            $stems->prepare($log, [100.0]);
            $this->fail('A failed separation must not pass.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('ffmpeg could not read', $exception->getMessage());
        }
        $this->assertNull($stems->read($log->fresh(), 100.0));
    }

    #[Test]
    public function the_separated_stretch_stays_inside_the_recording(): void
    {
        $stems = new SongEndStems(app(ServiceArtifactStorage::class), $this->storage());

        $this->assertSame(['start' => 0.0, 'end' => 20.0], $stems->window(4.0, 5000.0));
        $this->assertSame(['start' => 4982.0, 'end' => 5000.0], $stems->window(4990.0, 5000.0));
    }

    private function log(): MediaProcessingLog
    {
        Storage::fake('local');
        config(['media-processing.storage.service_artifact_disk' => 'local']);
        $log = MediaProcessingLog::factory()->livestream()->create(['duration' => 5000]);
        $log->writeProcessingMetadata(fn (array $metadata): array => [...$metadata,
            'service_artifacts' => [['kind' => 'audio', 'disk' => 'local', 'path' => 'service-audio/test.mp3']]]);

        return $log->fresh() ?? $log;
    }

    private function storage(): StorageAdapterHelper
    {
        $storage = \Mockery::mock(StorageAdapterHelper::class);
        $storage->shouldReceive('downloadToTemp')->andReturn('/audio.mp3');
        $storage->shouldReceive('isS3CompatibleDisk')->andReturn(false);

        return $storage;
    }
}
