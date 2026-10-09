<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Arr;

/** Explicit asset version: bump in config whenever cutting or enhancement behaviour changes. */
class MediaProcessingVersion
{
    /** Database JSON may reorder object keys; compare settings independently of that order. */
    public static function matches(mixed $stored): bool
    {
        return is_array($stored) && Arr::sortRecursive($stored) === Arr::sortRecursive(self::signature());
    }

    /** @return array{version: int, video: array<string, mixed>, enhancement: array<string, mixed>, audio_treatment: array<string, mixed>} */
    public static function signature(): array
    {
        return [
            'version' => (int) config('media-processing.media_processing_version'),
            'video' => [
                'codec' => 'libx264', 'audio_codec' => 'aac', 'sample_rate' => 48000, 'audio_bitrate' => 128000,
                'crf' => (int) config('media-processing.video_extraction.reencode_crf', 23),
                'preset' => (string) config('media-processing.video_extraction.reencode_preset', 'faster'),
            ],
            'enhancement' => (array) json_decode(json_encode(config('media-processing.audio_enhancement', []), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR),
            'audio_treatment' => (array) json_decode(json_encode(config('media-processing.audio_treatment', []), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR),
        ];
    }
}
