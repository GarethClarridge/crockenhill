<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Data\ChurchServiceTranscript;
use App\Models\MediaProcessingLog;
use App\Services\Media\Audio\ServiceTranscriptRedecoder;
use RuntimeException;

/**
 * The H10b listening routes: the operator's verdict, per run, on whether a fresh decode reads
 * better than the stored transcript (plan §4.0).
 *
 * A run routed `new_better` has grounds for Tier A as a live transcript-loss hold does, so the
 * 128 such runs need no hand-written holds. Like a hold, a route describes one transcript:
 * each carries the hash of the text listening compared (the decode artifact's
 * `stored_transcript.sha256`), and it lapses once the run holds different text. 1112 and 1287
 * were re-transcribed after their decodes, and a second Whisper pass would read no
 * better-judged text than the first.
 *
 * The routing file is scratch evidence outside the repository, so it is read only against the
 * sha256 the operator states for it. The snapshot then carries the members' routes, so both
 * tiers read the same frozen routes and neither can strand a run the other should take.
 *
 * Delete once the corpus re-run's batches are accepted, alongside its other instruments.
 */
final readonly class ListeningRouting
{
    public const KIND = 'h10b_listening_routing';

    public const NEW_BETTER = 'new_better';

    /**
     * @param  array<int, array{route: string, listened_transcript_sha256: string|null}>  $runs
     */
    public function __construct(
        public string $fileSha256,
        public array $runs,
    ) {}

    public static function fromFile(string $path, mixed $expectedSha256): self
    {
        if (! is_string($expectedSha256) || trim($expectedSha256) === '') {
            throw new RuntimeException('A listening routing file needs --routing-sha256, the hash it was scored under.');
        }

        $contents = is_file($path) ? file_get_contents($path) : false;

        if (! is_string($contents)) {
            throw new RuntimeException("Listening routing {$path} could not be read.");
        }

        $fileSha256 = hash('sha256', $contents);

        if (! hash_equals(strtolower(trim($expectedSha256)), $fileSha256)) {
            throw new RuntimeException("Listening routing {$path} hashes to {$fileSha256}, not the stated sha256.");
        }

        $data = json_decode($contents, true);

        if (! is_array($data) || ($data['kind'] ?? null) !== self::KIND || ! is_array($data['runs'] ?? null)) {
            throw new RuntimeException("{$path} is not an H10b listening routing file.");
        }

        return self::fromArray(['file_sha256' => $fileSha256, 'runs' => $data['runs']]);
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $runs = [];

        foreach ((array) ($data['runs'] ?? []) as $runId => $run) {
            if (! is_array($run) || ! is_string($run['route'] ?? null)) {
                continue;
            }

            $listened = $run['listened_transcript_sha256'] ?? null;
            $runs[(int) $runId] = [
                'route' => $run['route'],
                'listened_transcript_sha256' => is_string($listened) ? $listened : null,
            ];
        }

        ksort($runs);

        return new self((string) ($data['file_sha256'] ?? ''), $runs);
    }

    /**
     * @param  list<int>  $runIds
     */
    public function only(array $runIds): self
    {
        return new self($this->fileSha256, array_intersect_key($this->runs, array_flip($runIds)));
    }

    /**
     * @return array{file_sha256: string, runs: array<string, array{route: string, listened_transcript_sha256: string|null}>}
     */
    public function toArray(): array
    {
        return [
            'file_sha256' => $this->fileSha256,
            'runs' => array_combine(array_map('strval', array_keys($this->runs)), array_values($this->runs)),
        ];
    }

    /**
     * Whether listening sends this run to Tier A: routed `new_better`, on the text it still holds.
     */
    public function routesToRetranscription(MediaProcessingLog $run): bool
    {
        $entry = $this->runs[$run->id] ?? null;

        if ($entry === null || $entry['route'] !== self::NEW_BETTER || $entry['listened_transcript_sha256'] === null) {
            return false;
        }

        $contents = $run->storedServiceTranscriptContents();
        $decoded = $contents === null ? null : json_decode($contents, true);

        if (! is_array($decoded)) {
            return false;
        }

        return hash_equals(
            $entry['listened_transcript_sha256'],
            ServiceTranscriptRedecoder::transcriptHash(ChurchServiceTranscript::fromArray($decoded)),
        );
    }
}
