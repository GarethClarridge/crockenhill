<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Data\ChurchServiceTranscript;
use App\Exceptions\OutputEdgeTimingsMissing;
use App\Models\MediaProcessingLog;
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Support\MediaProcessingVersion;
use App\Support\ServiceArtifactDisk;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Durable edge-only evidence. Readers never decode audio or borrow full-service word timings. */
class OutputEdgeWordTimings
{
    /** @return list<array{start: float, end: float, text: string}> */
    public function cues(MediaProcessingLog $log): array
    {
        $path = $log->serviceTranscriptPath();
        if ($path === null) {
            return [];
        }
        $raw = Storage::disk(ServiceArtifactDisk::for($path))->get($path);
        if (! is_string($raw)) {
            throw new RuntimeException('The transcript required for cue-safe cuts could not be read.');
        }

        return ChurchServiceTranscript::fromArray(json_decode($raw, true, flags: JSON_THROW_ON_ERROR))->cues;
    }

    /**
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @return array{start: float, end: float, cues: list<array{start: float, end: float, text: string}>}|null
     */
    public function window(array $cues, float $edge, ?float $duration): ?array
    {
        $touching = array_values(array_filter($cues, static fn (array $cue): bool => $cue['start'] <= $edge + 0.001 && $cue['end'] >= $edge - 0.001));
        if ($touching === []) {
            return null;
        }
        $start = min(array_column($touching, 'start'));
        $end = max(array_column($touching, 'end'));

        return ['start' => max(0.0, $start - 1), 'end' => min($duration ?? INF, $end + 1), 'cues' => $touching];
    }

    /** @param array{start: float, end: float, cues?: list<array{start: float, end: float, text: string}>} $window
     * @return array<string, mixed>
     */
    public function identity(MediaProcessingLog $log, array $window): array
    {
        $provider = (string) config('media-processing.service_structure.transcription_service', 'mock');
        $model = match ($provider) {
            'local' => (string) config('media-processing.transcription.local_whisper_model', 'small'),
            'openai' => (string) config('media-processing.service_structure.transcription_model', 'whisper-1'),
            'mock' => 'mock',
            default => throw new RuntimeException('Unknown edge transcription provider: '.$provider),
        };
        $identity = ['processing_id' => $log->processing_id, 'start' => $window['start'], 'end' => $window['end'],
            'model' => $model, 'media_processing' => MediaProcessingVersion::signature()];

        // Existing edge artifacts were exclusively local. Preserve those receipts;
        // every other provider gets its own namespace, even with the same model name.
        return $provider === 'local' ? $identity : [...$identity, 'provider' => $provider];
    }

    /** @param array<string, mixed> $identity */
    public function kind(array $identity): string
    {
        return 'edge-words-'.hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** @param array{start: float, end: float, cues?: list<array{start: float, end: float, text: string}>} $window
     * @return array<string, mixed>
     */
    public function read(MediaProcessingLog $log, array $window): array
    {
        $identity = $this->identity($log, $window);
        $kind = $this->kind($identity);
        foreach (ServiceArtifactStorage::recordedFor($log) as $entry) {
            if ($entry['kind'] !== $kind) {
                continue;
            }
            try {
                $raw = Storage::disk($entry['disk'])->get($entry['path']);
            } catch (\Throwable) {
                $raw = null;
            }
            $payload = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($payload) && MediaProcessingVersion::matches($payload['identity']['media_processing'] ?? null)
                && ($payload['identity']['processing_id'] ?? null) === $log->processing_id
                && (float) ($payload['identity']['start'] ?? -1) === $window['start']
                && (float) ($payload['identity']['end'] ?? -1) === $window['end']
                && ($payload['identity']['provider'] ?? 'local') === ($identity['provider'] ?? 'local')
                && ($payload['identity']['model'] ?? null) === $identity['model'] && is_array($payload['words'] ?? null)) {
                $words = [];
                foreach ($payload['words'] as $word) {
                    if (! is_array($word) || ! is_numeric($word['start'] ?? null) || ! is_numeric($word['end'] ?? null)
                        || ! is_finite((float) $word['start']) || ! is_finite((float) $word['end'])
                        || $word['end'] < $word['start'] || ! is_string($word['word'] ?? null)) {
                        throw new OutputEdgeTimingsMissing('edge_word_timings_missing: invalid cached words for '.$kind);
                    }
                    $words[] = ['start' => (float) $word['start'], 'end' => (float) $word['end'], 'word' => $word['word']];
                }
                usort($words, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
                $lexical = [];
                foreach ($words as $word) {
                    if (preg_match('/[\p{L}\p{N}]/u', $word['word']) !== 1) {
                        $last = count($lexical) - 1;
                        if ($last >= 0) {
                            $lexical[$last]['word'] .= trim($word['word']);
                            $lexical[$last]['end'] = max($lexical[$last]['end'], $word['end']);
                        }

                        continue;
                    }
                    $lexical[] = $word;
                }
                $payload['words'] = $lexical;

                return $payload;
            }
        }

        throw new OutputEdgeTimingsMissing(sprintf('edge_word_timings_missing: run %s window %.6f–%.6f model %s', $log->processing_id, $window['start'], $window['end'], $identity['model']));
    }
}
