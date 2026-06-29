<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\MediaType;
use App\Models\MediaProcessingLog;
use Illuminate\Validation\Rule;

final class VideoProcessingOptions
{
    /**
     * @return array<string, mixed>
     */
    public static function forMediaType(?string $mediaType, bool $autoTrim, ?string $videoProcessingMode = null): array
    {
        if ($mediaType !== MediaType::Video->value) {
            return [];
        }

        return self::forVideo($autoTrim, $videoProcessingMode);
    }

    /**
     * @return array<string, mixed>
     */
    public static function forVideo(bool $autoTrim, ?string $videoProcessingMode = null): array
    {
        $mode = self::resolveMode([
            'auto_trim' => $autoTrim,
            'video_processing_mode' => $videoProcessingMode,
        ]);

        if ($mode !== MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM) {
            return [];
        }

        return [
            'auto_trim' => true,
            'video_processing_mode' => $mode,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public static function resolveMode(array $options): string
    {
        $mode = is_string($options['video_processing_mode'] ?? null)
            ? $options['video_processing_mode']
            : null;

        if ($mode === MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM) {
            return MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM;
        }

        return ($options['auto_trim'] ?? false) === true
            ? MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM
            : MediaProcessingLog::VIDEO_PROCESSING_MODE_FULL_VIDEO;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public static function requestsAutoTrim(array $options): bool
    {
        return self::resolveMode($options) === MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM;
    }

    /**
     * Shared validation rules for auto-trim fields on video uploads.
     *
     * @return array<string, array<mixed>>
     */
    public static function validationRules(MediaType|string|null $mediaType): array
    {
        $resolved = $mediaType instanceof MediaType ? $mediaType : MediaType::tryFrom((string) $mediaType);

        $autoTrimProhibited = $resolved !== MediaType::Video
            || ! (bool) config('media-processing.video_auto_trim.enabled', true);

        return [
            'auto_trim' => ['sometimes', 'boolean', Rule::prohibitedIf($autoTrimProhibited)],
            'video_processing_mode' => [
                'sometimes',
                'string',
                'max:255',
                Rule::in([
                    MediaProcessingLog::VIDEO_PROCESSING_MODE_FULL_VIDEO,
                    MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM,
                ]),
                Rule::prohibitedIf($autoTrimProhibited),
            ],
        ];
    }
}
