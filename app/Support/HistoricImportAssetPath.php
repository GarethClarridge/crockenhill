<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\MediaProcessingLog;
use App\Models\Sermon;

final class HistoricImportAssetPath
{
    public static function isHistoricProcessing(string $processingId): bool
    {
        $metadata = MediaProcessingLog::query()
            ->where('processing_id', $processingId)
            ->first()
            ?->processing_metadata
            ?->toArray();

        return is_array($metadata) && is_array($metadata['historic_import'] ?? null);
    }

    public static function forSermon(Sermon $sermon): ?string
    {
        $processingId = $sermon->livestreamProcessing?->processing_id;

        return is_string($processingId) && self::isHistoricProcessing($processingId) ? $processingId : null;
    }

    public static function video(string $processingId): string
    {
        return self::prefix($processingId).'/sermon/video.mp4';
    }

    public static function transcript(string $processingId): string
    {
        return self::prefix($processingId).'/sermon/transcript.md';
    }

    public static function thumbnail(string $processingId, string $variant): string
    {
        $safeVariant = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $variant) ?: 'thumbnail';

        return self::prefix($processingId)."/sermon/thumbnails/{$safeVariant}.webp";
    }

    private static function prefix(string $processingId): string
    {
        return "historic-imports/{$processingId}";
    }
}
