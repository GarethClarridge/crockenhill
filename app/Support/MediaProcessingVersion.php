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
            'video' => self::video(),
            'enhancement' => (array) json_decode(json_encode(config('media-processing.audio_enhancement', []), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR),
            'audio_treatment' => (array) json_decode(json_encode(config('media-processing.audio_treatment', []), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * libx264's entry is unchanged from before VideoToolbox, so its stamps still match.
     *
     * @return array<string, mixed>
     */
    private static function video(): array
    {
        $audio = ['audio_codec' => 'aac', 'sample_rate' => 48000, 'audio_bitrate' => 128000];

        if (config('media-processing.video_extraction.encoder') === 'h264_videotoolbox') {
            return [
                'codec' => 'h264_videotoolbox', ...$audio,
                'quality' => (int) config('media-processing.video_extraction.videotoolbox_quality'),
                'keyframe_interval' => (int) config('media-processing.video_extraction.videotoolbox_keyframe_interval'),
            ];
        }

        return [
            'codec' => 'libx264', ...$audio,
            'crf' => (int) config('media-processing.video_extraction.reencode_crf', 23),
            'preset' => (string) config('media-processing.video_extraction.reencode_preset', 'faster'),
        ];
    }
}
