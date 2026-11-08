<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Jobs\ClassifyServiceAudio;
use App\Models\MediaProcessingLog;
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Services\Processing\StorageAdapterHelper;
use App\Support\MediaProcessingVersion;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The voice and the instruments around a song's cut, separated (RA3e, video plan §4.1d, heard
 * 11-08): `scripts/separate_song_end.py` runs Demucs over the cut − {@see self::BEFORE} s to the cut
 * + {@see self::AFTER} s and banks each track's level every 0.1 s, and the instrument track itself,
 * which the song's clip plays once the talking starts.
 *
 * Banked by {@see PrepareOutputEdgeEvidence} for every song end that fades into speech; read by
 * {@see SongEndOutro}. Like the edge decodes, a reader never separates audio.
 */
class SongEndStems
{
    public const RULE = 'song-end-stems-v1';

    public const MODEL = 'htdemucs';

    /** Long enough to read the singing's level before the speech (the outro reference is 3 s). */
    private const BEFORE = 8.0;

    /** An outro can die away well after the talking starts (3891: 5.4 s after the cut). */
    private const AFTER = 16.0;

    /** The container measured 13 s for 24 s of audio (11-08). */
    private const TIMEOUT_SECONDS = 600;

    public function __construct(
        private ServiceArtifactStorage $artifacts,
        private StorageAdapterHelper $storage,
    ) {}

    /** @return array{start: float, end: float} */
    public function window(float $cut, ?float $duration): array
    {
        return ['start' => max(0.0, $cut - self::BEFORE), 'end' => min($duration ?? INF, $cut + self::AFTER)];
    }

    /** @return array<string, mixed> */
    public function identity(MediaProcessingLog $log, float $cut): array
    {
        return ['processing_id' => $log->processing_id, ...$this->window($cut, $log->duration), 'rule' => self::RULE,
            'model' => self::MODEL, 'media_processing' => MediaProcessingVersion::signature()];
    }

    /** @param array<string, mixed> $identity */
    public function kind(array $identity): string
    {
        return 'song-end-stems-'.hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * The banked separation around a cut; null when none is banked for this version.
     *
     * @return array{start: float, frame_seconds: float, voice_db: list<float>, instruments_db: list<float>, instruments: array{disk: string, path: string}}|null
     */
    public function read(MediaProcessingLog $log, float $cut): ?array
    {
        $kind = $this->kind($this->identity($log, $cut));
        $entries = ServiceArtifactStorage::recordedFor($log);
        $levels = array_values(array_filter($entries, static fn (array $entry): bool => $entry['kind'] === $kind))[0] ?? null;
        $track = array_values(array_filter($entries, static fn (array $entry): bool => $entry['kind'] === $kind.'-instruments'))[0] ?? null;
        if ($levels === null || $track === null) {
            return null;
        }
        try {
            $raw = Storage::disk($levels['disk'])->get($levels['path']);
        } catch (\Throwable) {
            return null;
        }
        $payload = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($payload) || ! MediaProcessingVersion::matches($payload['identity']['media_processing'] ?? null)
            || ! is_numeric($payload['start'] ?? null) || ! is_numeric($payload['frame_seconds'] ?? null)
            || ! is_array($payload['voice_db'] ?? null) || ! is_array($payload['instruments_db'] ?? null)
            || ! array_is_list($payload['voice_db']) || ! array_is_list($payload['instruments_db'])) {
            return null;
        }

        return ['start' => (float) $payload['start'], 'frame_seconds' => (float) $payload['frame_seconds'],
            'voice_db' => array_map(floatval(...), $payload['voice_db']), 'instruments_db' => array_map(floatval(...), $payload['instruments_db']),
            'instruments' => ['disk' => $track['disk'], 'path' => $track['path']]];
    }

    /**
     * Separate and bank the audio around each cut not already banked.
     *
     * @param  list<float>  $cuts
     * @return array{cuts: int, separated: int, compute_seconds: float}
     */
    public function prepare(MediaProcessingLog $log, array $cuts): array
    {
        $pending = array_values(array_filter(array_unique($cuts, SORT_REGULAR), fn (float $cut): bool => $this->read($log, $cut) === null));
        $summary = ['cuts' => count($cuts), 'separated' => count($pending), 'compute_seconds' => 0.0];
        if ($pending === []) {
            return $summary;
        }
        $audio = ClassifyServiceAudio::recordedAudio($log);
        if ($audio === null) {
            throw new RuntimeException('song_end_stems_source_missing: no archived service audio');
        }
        $local = $this->storage->downloadToTemp($audio['path'], $audio['disk'], 'local', 'temp/song-end-stems');
        try {
            foreach ($pending as $cut) {
                $started = microtime(true);
                $this->separate($log, $cut, $local);
                $summary['compute_seconds'] += microtime(true) - $started;
            }
        } finally {
            if ($this->storage->isS3CompatibleDisk(Storage::disk($audio['disk']))) {
                $this->storage->cleanupTempFile($local);
            }
        }

        return $summary;
    }

    private function separate(MediaProcessingLog $log, float $cut, string $localAudio): void
    {
        $identity = $this->identity($log, $cut);
        $kind = $this->kind($identity);
        $outDir = storage_path('app/temp/song-end-stems/'.Str::uuid());
        File::ensureDirectoryExists($outDir);
        try {
            $result = Process::timeout(self::TIMEOUT_SECONDS)->run(['python3', base_path('scripts/separate_song_end.py'),
                $localAudio, sprintf('%.3F', $identity['start']), sprintf('%.3F', $identity['end']), $outDir]);
            if (! $result->successful()) {
                throw new RuntimeException('Song-end separation failed: '.trim($result->errorOutput()));
            }
            $payload = json_decode($result->output(), true);
            $track = $outDir.'/instruments.flac';
            if (! is_array($payload) || ($payload['model'] ?? null) !== self::MODEL || ! is_array($payload['voice_db'] ?? null)
                || ! is_array($payload['instruments_db'] ?? null) || ! is_file($track)) {
                throw new RuntimeException('Song-end separation returned no levels or no instrument track.');
            }
            $this->artifacts->putFile($log->processing_id, $kind.'-instruments', $track, 'flac');
            $this->artifacts->putJson($log->processing_id, $kind, ['identity' => $identity,
                ...array_intersect_key($payload, array_flip(['model_signature', 'sample_rate', 'start', 'end', 'frame_seconds', 'voice_db', 'instruments_db', 'runtime'])),
                'recorded_at' => now()->toIso8601String()]);
        } finally {
            File::deleteDirectory($outDir);
        }
    }
}
