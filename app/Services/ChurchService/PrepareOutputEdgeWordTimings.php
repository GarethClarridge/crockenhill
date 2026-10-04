<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Exceptions\OutputEdgeTimingsMissing;
use App\Jobs\ClassifyServiceAudio;
use App\Models\MediaProcessingLog;
use App\Contracts\ServiceTranscriptionInterface;
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Services\Media\Audio\ServiceAudioWindowExtractor;
use App\Services\Processing\StorageAdapterHelper;
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
    public function prepare(MediaProcessingLog $log, array $spans): array
    {
        $cues = $this->evidence->cues($log);
        $windows = [];
        foreach ($spans as $span) {
            foreach ($span as $edge) {
                $window = $this->evidence->window($cues, $edge, $log->duration);
                if ($window !== null) {
                    $windows[$this->evidence->kind($this->evidence->identity($log, $window))] = $window;
                }
            }
        }
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
                    $words = $this->transcription->transcribeEdgeWindow($clip);
                } finally {
                    $this->extractor->delete($clip);
                }
                foreach ($words as &$word) {
                    $word['start'] += $window['start'];
                    $word['end'] += $window['start'];
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
}
