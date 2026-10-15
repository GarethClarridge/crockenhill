<?php

declare(strict_types=1);

namespace App\Services\Sermon;

use App\Enums\TalkType;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;

/**
 * The sermon a better recording of the same service already holds.
 *
 * One Sunday can reach the pipeline twice: a local capture of the sermon alone and a
 * livestream download of the whole service (1126 and 894, 17 November 2024). The service
 * takes its structure and songs from the fuller recording, but the sermon belongs to the
 * better one, so a run whose own copy is worse adopts that sermon instead of cutting its
 * own (operator, 2026-10-15). Better means a higher resolution, then a higher bitrate, and
 * the held sermon must run at least {@see self::MINIMUM_COVERAGE} of this run's, so a
 * capture that started partway through never wins on picture alone.
 */
class BetterSermonRecording
{
    public const MINIMUM_COVERAGE = 0.95;

    /**
     * @return array{sermon: Sermon, holder: MediaProcessingLog}|null
     */
    public function holderFor(MediaProcessingLog $run, float $sermonDuration): ?array
    {
        $quality = self::quality($run);

        // A run that already made its own sermon keeps it; replacing one is a re-cut's decision.
        if ($run->church_service_id === null || $quality === null
            || Sermon::query()->where('livestream_processing_id', $run->processing_id)->exists()) {
            return null;
        }

        $best = null;

        $recordings = MediaProcessingLog::query()
            ->where('church_service_id', $run->church_service_id)
            ->whereKeyNot($run->getKey())
            ->get();

        foreach ($recordings as $recording) {
            $recordingQuality = self::quality($recording);

            if ($recording->isExcluded() || $recordingQuality === null || $recordingQuality <= $quality
                || ($best !== null && $recordingQuality <= self::quality($best['holder']))) {
                continue;
            }

            $sermon = Sermon::query()
                ->where('livestream_processing_id', $recording->processing_id)
                ->where('content_type', TalkType::Sermon)
                ->first();

            if ($sermon instanceof Sermon && (float) $sermon->duration >= self::MINIMUM_COVERAGE * $sermonDuration) {
                $best = ['sermon' => $sermon, 'holder' => $recording];
            }
        }

        return $best;
    }

    /**
     * Record that the run takes the held sermon as the service's own instead of making one.
     * Nothing is written to the sermon: no later step of this run may reach it.
     *
     * @param  array{sermon: Sermon, holder: MediaProcessingLog}  $adopted
     */
    public function adopt(MediaProcessingLog $run, array $adopted): void
    {
        $metadata = $run->processing_metadata?->toArray() ?? [];
        $metadata['sermon_adopted'] = [
            'sermon_id' => $adopted['sermon']->id,
            'holder_processing_id' => $adopted['holder']->processing_id,
            'holder_quality' => self::quality($adopted['holder']),
            'own_quality' => self::quality($run),
            'adopted_at' => now()->toIso8601String(),
        ];
        $run->forceFill(['processing_metadata' => $metadata])->save();
    }

    /**
     * The recording's picture, compared height first, then bitrate.
     *
     * @return array{int, int}|null
     */
    public static function quality(MediaProcessingLog $run): ?array
    {
        $format = data_get($run->processing_metadata?->toArray() ?? [], 'format_details');
        $height = data_get($format, 'height');

        if (! is_numeric($height)) {
            return null;
        }

        return [(int) $height, (int) data_get($format, 'bit_rate', 0)];
    }
}
