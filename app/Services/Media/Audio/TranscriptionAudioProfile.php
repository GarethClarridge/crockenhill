<?php

declare(strict_types=1);

namespace App\Services\Media\Audio;

final class TranscriptionAudioProfile
{
    /** @return array{bitrate: int, channels: int, max_file_size: int} */
    public static function optimized(): array
    {
        return [
            'bitrate' => (int) config('media-processing.audio_extraction.transcription_optimized.bitrate', 48),
            'channels' => (int) config('media-processing.audio_extraction.transcription_optimized.channels', 1),
            'max_file_size' => (int) config('media-processing.audio_extraction.transcription_optimized.max_file_size', 25 * 1024 * 1024),
        ];
    }

    /** @return array{bitrate: int, channels: int} */
    public static function fallback(): array
    {
        return [
            'bitrate' => (int) config('media-processing.audio_extraction.fallback_compression.bitrate', 32),
            'channels' => (int) config('media-processing.audio_extraction.fallback_compression.channels', 1),
        ];
    }
}
