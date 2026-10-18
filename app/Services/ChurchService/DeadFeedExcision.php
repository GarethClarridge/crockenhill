<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\Structure\DeadFeedInsideSection;
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Services\Media\Video\VideoDeadPictureProbe;
use App\Services\Processing\StorageAdapterHelper;
use App\Support\ServiceArtifactDisk;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * E20: remove only corroborated dead sound and picture strictly inside a talk's final cut.
 * Evidence is collected upstream; resolving a plan never probes or writes anything.
 * Excision follows word/sentence placement: unlike a boundary between spoken items, this seam
 * contains no recoverable speech. Reapplying cue widening could restore the very dead feed E20
 * removes. The existing multi-span transcript slicer still owns the text on either side.
 */
class DeadFeedExcision
{
    public const ARTIFACT_KIND = 'dead-picture';

    public function __construct(
        private readonly DeadFeedInsideSection $deadAudio,
        private readonly VideoDeadPictureProbe $picture,
    ) {}

    /** @return array{processing_id: string, path: string|null, size: int|null, sha256: string|null} */
    public function sourceIdentity(MediaProcessingLog $log): array
    {
        return ['processing_id' => $log->processing_id, 'path' => $log->source_file_path, 'size' => $log->file_size, 'sha256' => $log->stagedSourceFileHash()];
    }

    /** Collect once before edge planning, including re-extractions that reuse their RMS log. */
    public function prepare(MediaProcessingLog $log, StorageAdapterHelper $storage): void
    {
        $rms = $this->rms($log);
        if ($rms === null || ! filled($log->source_file_path)) {
            return;
        }
        $candidates = $this->candidateDropouts($log, $rms);
        if ($candidates === []) {
            return;
        }
        $existing = $this->read($log);
        if (($existing['source'] ?? null) === $this->sourceIdentity($log)
            && ($existing['rms_sha256'] ?? null) === hash('sha256', $rms)
            && ($existing['audio_candidates'] ?? null) == $candidates) {
            return;
        }
        $disk = (string) config('media-processing.storage.temp_disk', 'local');
        if (! Storage::disk($disk)->exists($log->source_file_path)) {
            return;
        }
        $local = $storage->downloadToTemp($log->source_file_path, $disk, 'local', 'temp/dead-picture');
        try {
            app(ServiceArtifactStorage::class)->putJson($log->processing_id, self::ARTIFACT_KIND, $this->measure($log, $local, $rms));
        } finally {
            if ($storage->isS3CompatibleDisk(Storage::disk($disk))) {
                $storage->cleanupTempFile($local);
            }
        }
        $log->refresh();
    }

    /**
     * Called only by the processing pipeline while it holds the local source video.
     * Detector margins allow a freeze to be established before the candidate audio interval.
     * Null detector results are banked explicitly; they never establish dead picture.
     *
     * @return array<string, mixed>
     */
    public function measure(MediaProcessingLog $log, string $localVideoPath, string $rms): array
    {
        $margin = max(
            (float) config('media-processing.video_quality.probe.freeze_min_seconds', 20.0),
            (float) config('media-processing.video_quality.probe.black_min_seconds', 5.0),
        );
        $windows = [];
        $candidates = $this->candidateDropouts($log, $rms);
        foreach ($candidates as [$from, $to]) {
            $start = max(0.0, $from - $margin);
            $end = $log->duration === null ? $to + $margin : min((float) $log->duration, $to + $margin);
            $coverage = $this->picture->probeWindow($localVideoPath, $start, $end);
            $windows[] = ['start' => $start, 'end' => $end, 'dead_intervals' => $coverage?->deadIntervals];
        }

        return ['source' => $this->sourceIdentity($log), 'rms_sha256' => hash('sha256', $rms),
            'probe_settings' => config('media-processing.video_quality.probe'), 'audio_candidates' => $candidates, 'windows' => $windows];
    }

    /** @return list<array{0: float, 1: float}> */
    private function candidateDropouts(MediaProcessingLog $log, string $rms): array
    {
        $talks = $log->serviceSections()->whereIn('section_type', [ServiceSectionType::Sermon->value, ServiceSectionType::ShortTalk->value])->get();

        return array_values(array_filter($this->deadAudio->dropouts($rms), static fn (array $dropout): bool => $talks->contains(
            static fn (ServiceSection $talk): bool => min((float) $talk->end_time, $dropout[1]) - max((float) $talk->start_time, $dropout[0]) >= DeadFeedInsideSection::MINIMUM_SECONDS,
        )));
    }

    /**
     * The hash binds both planners to exactly the banked evidence, including an unreadable result.
     */
    public function identity(MediaProcessingLog $log): ?string
    {
        $evidence = $this->read($log);

        return $evidence === null ? null : hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR));
    }

    /** Bind a cut to the current source, RMS and banked picture evidence, including stale artifacts. */
    public function inputIdentity(MediaProcessingLog $log): string
    {
        $rms = $this->rms($log);

        return hash('sha256', json_encode([
            'source' => $this->sourceIdentity($log),
            'rms_sha256' => $rms === null ? null : hash('sha256', $rms),
            'picture_sha256' => $this->identity($log),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * The source dropout flag is deliberately retained as a review note even after excision:
     * the feed lost words and E20 cannot recover them. SectionReviewFlagPolicy still requests
     * review, while SermonAutoExtractionPolicy still allows preparation of the media for review.
     * No stored flag or hold is silently withdrawn by resolving a cut.
     *
     * @param  list<ServiceSection>  $sections
     * @param  list<array{start_time: float, end_time: float}>  $spans
     * @param  array<string, mixed>|null  $evidence  Explicit evidence for read-only replay; never banked here
     * @return array{segments: list<array{start_time: float, end_time: float}>, dead_feed_excisions: list<array<string, mixed>>}
     */
    public function apply(MediaProcessingLog $log, array $sections, array $spans, ?array $evidence = null): array
    {
        $result = ['segments' => $spans, 'dead_feed_excisions' => []];
        $rms = $this->rms($log);
        $evidence ??= $this->read($log);
        if (! filled($log->source_file_path) || $rms === null || $evidence === null
            || ($evidence['source'] ?? null) !== $this->sourceIdentity($log)
            || ($evidence['rms_sha256'] ?? null) !== hash('sha256', $rms)
            || ! is_array($evidence['windows'] ?? null)) {
            return $result;
        }
        $deadPicture = [];
        foreach ($evidence['windows'] as $window) {
            if (! is_array($window) || ! is_array($window['dead_intervals'] ?? null)) {
                continue;
            }
            foreach ($window['dead_intervals'] as $interval) {
                if (is_array($interval) && count($interval) === 2 && is_numeric($interval[0] ?? null) && is_numeric($interval[1] ?? null)
                    && is_finite((float) $interval[0]) && is_finite((float) $interval[1])
                    && is_numeric($window['start'] ?? null) && is_numeric($window['end'] ?? null)
                    && $interval[0] >= $window['start'] && $interval[1] <= $window['end'] && $interval[1] > $interval[0]) {
                    $deadPicture[] = [(float) $interval[0], (float) $interval[1]];
                }
            }
        }
        $cuts = [];
        foreach ($this->deadAudio->dropouts($rms) as [$audioStart, $audioEnd]) {
            foreach ($deadPicture as [$pictureStart, $pictureEnd]) {
                $from = max($audioStart, $pictureStart);
                $to = min($audioEnd, $pictureEnd);
                if ($to - $from < DeadFeedInsideSection::MINIMUM_SECONDS) {
                    continue;
                }
                foreach ($sections as $section) {
                    if (in_array($section->section_type, [ServiceSectionType::Sermon, ServiceSectionType::ShortTalk], true)
                        && $from > (float) $section->start_time && $to < (float) $section->end_time) {
                        $cuts[] = [$from, $to];
                    }
                }
            }
        }
        usort($cuts, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($cuts as [$from, $to]) {
            $last = array_key_last($merged);
            if ($last !== null && $from <= $merged[$last][1]) {
                $merged[$last][1] = max($to, $merged[$last][1]);
            } else {
                $merged[] = [$from, $to];
            }
        }
        foreach ($merged as [$from, $to]) {
            $remaining = [];
            foreach ($result['segments'] as $span) {
                // Edge snapping owns dropouts at the final cut's edges. E20 creates interior seams only.
                if ($from <= $span['start_time'] || $to >= $span['end_time']) {
                    $remaining[] = $span;

                    continue;
                }
                $remaining[] = ['start_time' => $span['start_time'], 'end_time' => $from];
                $remaining[] = ['start_time' => $to, 'end_time' => $span['end_time']];
                $result['dead_feed_excisions'][] = ['ruling' => 'E20', 'start_time' => $from, 'end_time' => $to,
                    'evidence_sha256' => hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR)),
                    'rms_sha256' => $evidence['rms_sha256'], 'source' => $evidence['source']];
            }
            $result['segments'] = $remaining;
        }

        return $result;
    }

    /** @return array<string, mixed>|null */
    private function read(MediaProcessingLog $log): ?array
    {
        $entry = null;
        foreach (ServiceArtifactStorage::recordedFor($log) as $artifact) {
            if ($artifact['kind'] === self::ARTIFACT_KIND) {
                $entry = $artifact;
            }
        }
        if ($entry === null) {
            return null;
        }
        try {
            $raw = Storage::disk($entry['disk'])->get($entry['path']);
            $decoded = is_string($raw) ? json_decode($raw, true, flags: JSON_THROW_ON_ERROR) : null;

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable $exception) {
            Log::warning('Dead-picture artifact unavailable', ['processing_id' => $log->processing_id, 'error' => $exception->getMessage()]);

            return null;
        }
    }

    private function rms(MediaProcessingLog $log): ?string
    {
        if (! filled($log->rms_log_path)) {
            return null;
        }
        try {
            return Storage::disk(ServiceArtifactDisk::for($log->rms_log_path))->get($log->rms_log_path);
        } catch (Throwable $exception) {
            Log::warning('Dead-feed RMS evidence unavailable', ['processing_id' => $log->processing_id, 'error' => $exception->getMessage()]);

            return null;
        }
    }
}
