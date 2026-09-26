<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Jobs\ClassifyServiceAudio;
use App\Services\Media\Audio\AudioChunkingService;
use Illuminate\Support\Facades\Storage;

/**
 * For tests that run a service transcription (the mock included) over placeholder bytes.
 *
 * Every service transcription compresses the recording and archives the result on the sermon
 * disk for {@see ClassifyServiceAudio}. This fakes that disk and "compresses" by
 * copying the input, prefixed, to a temp file the service removes, so no ffmpeg runs.
 */
trait CompressesServiceAudioWithoutFfmpeg
{
    protected function compressServiceAudioWithoutFfmpeg(): void
    {
        Storage::fake((string) config('media-processing.storage.sermon_disk'));

        $this->mock(AudioChunkingService::class)
            ->shouldReceive('compressAudioForTranscription')
            ->andReturnUsing(function (string $inputPath): string {
                $compressedPath = (string) tempnam(sys_get_temp_dir(), 'compressed');
                file_put_contents($compressedPath, 'compressed '.file_get_contents($inputPath));

                return $compressedPath;
            });
    }
}
