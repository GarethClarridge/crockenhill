<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Contracts\ServiceTranscriptionInterface;
use App\Data\ChurchServiceTranscript;
use App\Exceptions\OutputEdgeTimingsMissing;
use App\Exceptions\TranscriptionCapabilityUnavailable;
use App\Jobs\ClassifyServiceAudio;
use App\Models\MediaProcessingLog;
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Services\Media\Audio\ServiceAudioWindowExtractor;
use App\Services\Processing\StorageAdapterHelper;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PrepareOutputEdgeWordTimings
{
    public function __construct(
        private OutputEdgeWordTimings $evidence,
        private ServiceTranscriptionInterface $transcription,
        private ServiceAudioWindowExtractor $extractor,
        private ServiceArtifactStorage $artifacts,
        private StorageAdapterHelper $storage,
    ) {}

    /** @param list<array{start_time: float, end_time: float}> $spans
     * @return array{windows: int, decoded: int, compute_seconds: float}
     */
    public function prepare(MediaProcessingLog $log, array $spans, ?ChurchServiceTranscript $transcript = null): array
    {
        return $this->prepareWindows($log, $this->windows($log, $spans, $transcript));
    }

    /**
     * @param  array<string, array{start: float, end: float, window_rule?: string, word_timing?: string}>  $windows
     * @return array{windows: int, decoded: int, compute_seconds: float, unavailable?: list<array{window: string, provider: string, reason: string}>}
     *                                                                                                                                                A window the provider cannot decode as asked is listed under `unavailable` and left unbanked, which every reader already treats as missing evidence.
     */
    public function prepareWindows(MediaProcessingLog $log, array $windows): array
    {
        $pending = [];
        foreach ($windows as $kind => $window) {
            try {
                $this->evidence->read($log, $window);
            } catch (OutputEdgeTimingsMissing) {
                $pending[$kind] = $window;
            }
        }
        $summary = ['windows' => count($windows), 'decoded' => count($pending), 'compute_seconds' => 0.0];
        if ($pending === []) {
            return $summary;
        }
        $audio = ClassifyServiceAudio::recordedAudio($log);
        if ($audio === null) {
            throw new RuntimeException('edge_word_timings_source_missing: no archived service audio');
        }
        $local = $this->storage->downloadToTemp($audio['path'], $audio['disk'], 'local', 'temp/output-edge-words');
        try {
            foreach ($pending as $kind => $window) {
                $started = microtime(true);
                $clip = $this->extractor->extract($local, $window['start'], $window['end'], $log->processing_id);
                try {
                    $words = isset($window['word_timing'])
                        ? $this->transcription->transcribeEdgeWindow($clip, aligned: true)
                        : $this->transcription->transcribeEdgeWindow($clip);
                } catch (TranscriptionCapabilityUnavailable $refusal) {
                    $summary['unavailable'][] = ['window' => $kind, 'provider' => $refusal->provider, 'reason' => $refusal->getMessage()];
                    Log::warning('Edge window unavailable: the transcription provider cannot decode it as asked', [
                        'processing_id' => $log->processing_id, 'window' => $kind, 'start' => $window['start'], 'end' => $window['end'],
                        'provider' => $refusal->provider, 'reason' => $refusal->getMessage()]);

                    continue;
                } finally {
                    $this->extractor->delete($clip);
                }
                foreach ($words as &$word) {
                    $word['start'] += $window['start'];
                    $word['end'] += $window['start'];
                    if (isset($word['aligned_end'])) {
                        $word['aligned_end'] += $window['start'];
                    }
                }
                unset($word);
                $compute = microtime(true) - $started;
                $identity = $this->evidence->identity($log, $window);
                $this->artifacts->putJson($log->processing_id, $kind, ['identity' => $identity, 'words' => $words,
                    'compute_seconds' => $compute, 'recorded_at' => now()->toIso8601String()], ['model' => $identity['model']]);
                $summary['compute_seconds'] += $compute;
            }
        } finally {
            if ($this->storage->isS3CompatibleDisk(Storage::disk($audio['disk']))) {
                $this->storage->cleanupTempFile($local);
            }
        }

        return $summary;
    }

    /** @param list<array{start_time: float, end_time: float}> $spans
     * @return array<string, array{start: float, end: float, cues: list<array{start: float, end: float, text: string}>, between?: true}>
     */
    public function windows(MediaProcessingLog $log, array $spans, ?ChurchServiceTranscript $transcript = null): array
    {
        $cues = $transcript->cues ?? $this->evidence->cues($log);
        $levels = app(OutputEdgeDecodeWindow::class)->levels($log);
        $windows = [];
        foreach ($spans as $span) {
            foreach ($span as $edge) {
                foreach ([$this->evidence->timingWindow($cues, $edge, $log->duration),
                    $this->evidence->window($cues, $edge, $log->duration, $log, $levels)] as $window) {
                    if ($window !== null) {
                        $windows[$this->evidence->kind($this->evidence->identity($log, $window))] = $window;
                    }
                }
            }
        }

        return $windows;
    }
}
