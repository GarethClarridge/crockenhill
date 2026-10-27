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
    public function __construct(private OutputEdgeDecodeWindow $decodeWindows) {}

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
     * Seconds either side of an edge within which the lines around it make it an edge inside
     * speech: 1117's sermon cut sat 0.15 s after "Amen." and 0.43 s before the next line, touched
     * no cue, and so was never decoded or checked (canary 11 heard the "Amen" lost).
     */
    private const GAP_REACH = 1.0;

    /**
     * The legacy cue window remains the minimum. With RMS evidence, extend to the nearest 0.8 s
     * quiet midpoint each side, capped at 15 s of extra reach from the edge; otherwise
     * retain cue bounds. The eligible cues are those touching the edge, or, for an edge in a short gap between two
     * lines, both lines; a margin of a second either side. Null for an edge in a longer silence,
     * which whole cues place.
     *
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @param  array{samples: list<array{time: float, rms: float}>, threshold: float}|null $levels
     * @return array{start: float, end: float, cues: list<array{start: float, end: float, text: string}>, between?: true, window_rule?: string, left_boundary?: string, right_boundary?: string}|null
     */
    public function window(array $cues, float $edge, ?float $duration, ?MediaProcessingLog $log = null, ?array $levels = null): ?array
    {
        $window = $this->timingWindow($cues, $edge, $duration);
        if ($window === null) {
            return null;
        }
        $levels ??= $log === null ? null : $this->decodeWindows->levels($log);
        if ($levels === null) {
            return $window;
        }

        $bounds = $this->decodeWindows->bounds($levels['samples'], $levels['threshold'], $edge, $duration);
        if ($window['start'] < $bounds['start']) {
            $bounds['start'] = $window['start'];
            $bounds['left_boundary'] = 'cue';
        }
        if ($window['end'] > $bounds['end']) {
            $bounds['end'] = $window['end'];
            $bounds['right_boundary'] = 'cue';
        }

        return [...$window, ...$bounds, 'window_rule' => OutputEdgeDecodeWindow::RULE];
    }

    /**
     * The original decode remains the sole word/pause timing source. No RMS or wide identity.
     *
     * @param list<array{start: float, end: float, text: string}> $cues
     * @return array{start: float, end: float, cues: list<array{start: float, end: float, text: string}>, between?: true, window_rule?: string, left_boundary?: string, right_boundary?: string}|null
     */
    public function timingWindow(array $cues, float $edge, ?float $duration): ?array
    {
        $touching = array_values(array_filter($cues, static fn (array $cue): bool => $cue['start'] <= $edge + 0.001 && $cue['end'] >= $edge - 0.001));
        if ($touching === []) {
            $before = array_filter($cues, static fn (array $cue): bool => $cue['end'] < $edge && $cue['end'] >= $edge - self::GAP_REACH);
            $after = array_filter($cues, static fn (array $cue): bool => $cue['start'] > $edge && $cue['start'] <= $edge + self::GAP_REACH);
            if ($before === [] || $after === []) {
                return null;
            }
            usort($before, static fn (array $a, array $b): int => $b['end'] <=> $a['end']);
            usort($after, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
            [$previous, $next] = [$before[0], $after[0]];

            return ['start' => max(0.0, $previous['start'] - 1), 'end' => min($duration ?? INF, $next['end'] + 1), 'cues' => [$previous, $next], 'between' => true];
        }
        $start = min(array_column($touching, 'start'));
        $end = max(array_column($touching, 'end'));

        return ['start' => max(0.0, $start - 1), 'end' => min($duration ?? INF, $end + 1), 'cues' => $touching];
    }

    /** @param array{start: float, end: float, cues?: list<array{start: float, end: float, text: string}>, window_rule?: string} $window
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

        if (isset($window['window_rule'])) {
            $identity['window_rule'] = $window['window_rule'];
        }

        // Existing edge artifacts were exclusively local. Preserve those receipts;
        // every other provider gets its own namespace, even with the same model name.
        return $provider === 'local' ? $identity : [...$identity, 'provider' => $provider];
    }

    /** @param array<string, mixed> $identity */
    public function kind(array $identity): string
    {
        return 'edge-words-'.hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** @param array{start: float, end: float, cues?: list<array{start: float, end: float, text: string}>, window_rule?: string} $window
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
                && ($payload['identity']['window_rule'] ?? null) === ($identity['window_rule'] ?? null)
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
                // whisper.cpp tokens start a word with a space and continue one without it
                // (" B", "ibles"): a gap between them is inside a word. Providers returning
                // whole words never lead with a space, so only punctuation joins there.
                $spaceMarksWords = array_any($words, static fn (array $word): bool => preg_match('/^\s/u', $word['word']) === 1);
                $lexical = [];
                foreach ($words as $word) {
                    if (preg_match('/[\p{L}\p{N}]/u', $word['word']) !== 1
                        || ($spaceMarksWords && $lexical !== [] && preg_match('/^\s/u', $word['word']) !== 1)) {
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

    /**
     * Every word the run's banked edge decodes heard, from each window {@see read()} still accepts:
     * windows overlap, so one decode can hear a word another lost to a dropout.
     *
     * @return list<array{start: float, end: float, word: string}>
     */
    public function heardWords(MediaProcessingLog $log): array
    {
        $words = [];
        // Wide text evidence must never change a timing guard's union of heard words.
        foreach (ServiceArtifactStorage::recordedFor($log) as $entry) {
            if (! str_starts_with($entry['kind'], 'edge-words-')) {
                continue;
            }
            try {
                $raw = Storage::disk($entry['disk'])->get($entry['path']);
            } catch (\Throwable) {
                $raw = null;
            }
            $payload = is_string($raw) ? json_decode($raw, true) : null;
            $identity = is_array($payload) && is_array($payload['identity'] ?? null) ? $payload['identity'] : [];
            if (($identity['window_rule'] ?? null) !== null) {
                continue;
            }
            try {
                if (is_numeric($identity['start'] ?? null) && is_numeric($identity['end'] ?? null)) {
                    $window = ['start' => (float) $identity['start'], 'end' => (float) $identity['end']];
                    array_push($words, ...$this->read($log, $window)['words']);
                }
            } catch (OutputEdgeTimingsMissing) {
                // A decode for another version or model is not this run's evidence.
            }
        }

        return $words;
    }
}
