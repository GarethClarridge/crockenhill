<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Data\ChurchServiceTranscript;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Support\ServiceArtifactDisk;
use Illuminate\Support\Facades\Storage;

/** Complete spoken cues belong to every output crossing them; sections remain unchanged. */
class CueSafeExtractionPlan
{
    /** @return array{segments: list<array{start_time: float, end_time: float}>, cue_edge_widening: list<array<string, mixed>>} */
    public function forSection(ServiceSection $section): array
    {
        return $this->forSpans($section->processingLog, [['start_time' => (float) $section->start_time, 'end_time' => (float) $section->end_time]]);
    }

    /**
     * @param  list<array{start_time: float, end_time: float}>  $spans
     * @return array{segments: list<array{start_time: float, end_time: float}>, cue_edge_widening: list<array<string, mixed>>}
     */
    public function forSpans(MediaProcessingLog $log, array $spans): array
    {
        $path = $log->serviceTranscriptPath();
        $cues = [];
        if ($path !== null && Storage::disk(ServiceArtifactDisk::for($path))->exists($path)) {
            $raw = Storage::disk(ServiceArtifactDisk::for($path))->get($path);
            if (! is_string($raw)) {
                throw new \RuntimeException('The transcript required for cue-safe cuts could not be read.');
            }
            $cues = ChurchServiceTranscript::fromArray(json_decode($raw, true, flags: JSON_THROW_ON_ERROR))->cues;
        }
        $audit = [];
        foreach ($spans as $index => &$span) {
            foreach (['start' => 'start_time', 'end' => 'end_time'] as $edge => $key) {
                $original = $span[$key];
                $time = $original;
                $included = [];
                do {
                    $before = $time;
                    foreach ($cues as $cue) {
                        if ($cue['start'] < $time && $cue['end'] > $time) {
                            $time = $edge === 'start' ? min($time, $cue['start']) : max($time, $cue['end']);
                            if (! in_array($cue, $included, true)) {
                                $included[] = $cue;
                            }
                        }
                    }
                } while ($before !== $time);
                $span[$key] = $time;
                if ($time !== $original) {
                    $included = array_values(array_filter($cues, static fn (array $cue): bool => $cue['start'] < max($time, $original) && $cue['end'] > min($time, $original)));
                    $audit[] = ['span_index' => $index, 'edge' => $edge, 'original_time' => $original,
                        'time' => $time, 'seconds_added' => abs($time - $original), 'cue' => $included[0], 'cues' => $included];
                }
            }
        }
        unset($span);
        $merged = [];
        foreach ($spans as $span) {
            $last = count($merged) - 1;
            if ($last >= 0 && $span['start_time'] <= $merged[$last]['end_time']) {
                $merged[$last]['end_time'] = max($merged[$last]['end_time'], $span['end_time']);

                continue;
            }
            $merged[] = $span;
        }

        return ['segments' => $merged, 'cue_edge_widening' => $audit];
    }
}
