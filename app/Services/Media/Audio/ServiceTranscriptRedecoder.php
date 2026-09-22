<?php

declare(strict_types=1);

namespace App\Services\Media\Audio;

use App\Data\ChurchServiceTranscript;
use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * H10b's expensive half: decode a completed historic run again, as evidence.
 *
 * The comparison is only evidential if the new decode differs from the stored one
 * in the decoder setting alone. The stored transcripts predate audio banking (no
 * historic run had its compressed audio archived before 2026-09-17), so "the same
 * audio" is the hash-verified staged source, compressed by the pipeline's own
 * method and fingerprinted. Nothing is written to the run: the new decode is not
 * adopted here, and adoption is decided per run through the tested path.
 *
 * An unreachable or unverifiable source is unassessable, never clean.
 *
 * @phpstan-type RedecodeResult array{outcome: 'decoded', artifact: array<string, mixed>}|array{outcome: 'unassessable', reason: string}
 */
final class ServiceTranscriptRedecoder
{
    public const SCHEMA = 'h10b-redecode-v1';

    /**
     * The files whose code decides what the new decode contains (H6's version binding).
     */
    private const DECODE_CODE = [
        'app/Services/Media/Audio/LocalWhisperServiceTranscriptionService.php',
        'app/Services/Media/Audio/LocalWhisperDecoding.php',
        'app/Services/Media/Audio/AudioChunkingService.php',
        'app/Services/Media/Audio/TranscriptionAudioProfile.php',
    ];

    public function __construct(
        private readonly HistoricStagingContextRegistry $stagingContexts,
        private readonly ServiceTranscriptReader $transcriptReader,
        private readonly AudioChunkingService $chunkingService,
        private readonly LocalWhisperServiceTranscriptionService $whisper,
    ) {}

    /** @return RedecodeResult */
    public function redecode(MediaProcessingLog $run): array
    {
        if ($run->status !== ProcessingStatus::Completed) {
            return $this->unassessable(sprintf('run is %s, not completed', $run->status->value));
        }

        if ($run->processing_type !== MediaType::Livestream) {
            return $this->unassessable('run is not a livestream pipeline');
        }

        $context = $run->historicStagingContext();

        if ($context === null) {
            return $this->unassessable('run has no historic staging context');
        }

        return $this->stagingContexts->within($context, fn (): array => $this->redecodeWithinContext($run));
    }

    /** @return RedecodeResult */
    private function redecodeWithinContext(MediaProcessingLog $run): array
    {
        $stored = $this->transcriptReader->tryRead($run);

        if ($stored === null) {
            return $this->unassessable('stored transcript is unavailable');
        }

        if ($stored->source !== ChurchServiceTranscript::SOURCE_LOCAL_WHISPER) {
            return $this->unassessable(sprintf('stored transcript came from %s, not local whisper', $stored->source));
        }

        $expectedHash = $run->recordedSourceFileHash();

        if ($expectedHash === null) {
            return $this->unassessable('run has no recorded source hash');
        }

        $sourcePath = $run->source_file_path;
        $disk = Storage::disk((string) config('media-processing.storage.temp_disk'));

        if (! is_string($sourcePath) || $sourcePath === '' || ! $disk->exists($sourcePath)) {
            return $this->unassessable('staged source is missing');
        }

        $localSourcePath = $disk->path($sourcePath);
        $actualHash = hash_file('sha256', $localSourcePath);

        if ($actualHash === false || ! hash_equals($expectedHash, $actualHash)) {
            return $this->unassessable('staged source hash does not match recorded evidence');
        }

        $decodeId = 'redecode-'.$run->id;

        try {
            $compressedPath = $this->chunkingService->compressAudioForTranscription($localSourcePath, $decodeId);
        } catch (Throwable $exception) {
            return $this->unassessable('audio compression failed: '.$exception->getMessage());
        }

        try {
            $compressedHash = (string) hash_file('sha256', $compressedPath);
            $compressedBytes = (int) filesize($compressedPath);
            $startedAt = microtime(true);
            $new = $this->whisper->decodeCompressedAudio($compressedPath, $decodeId);
            $decodeSeconds = round(microtime(true) - $startedAt, 1);
        } catch (Throwable $exception) {
            return $this->unassessable('decode failed: '.$exception->getMessage());
        } finally {
            if (file_exists($compressedPath)) {
                unlink($compressedPath);
            }
        }

        return [
            'outcome' => 'decoded',
            'artifact' => [
                'schema' => self::SCHEMA,
                'run_id' => $run->id,
                'processing_id' => $run->processing_id,
                'source' => ['path' => $sourcePath, 'sha256' => $actualHash],
                'stored_transcript' => [
                    'path' => $run->serviceTranscriptPath(),
                    'sha256' => self::transcriptHash($stored),
                    'duration' => $stored->duration,
                    'suspect_blocks' => $run->recordedTranscriptSuspectBlocks(),
                    'stratum' => self::stratum($run),
                ],
                'compressed_audio' => ['sha256' => $compressedHash, 'bytes' => $compressedBytes],
                'decode' => [
                    'request' => $this->whisper->requestOptions(),
                    'code' => $this->codeHashes(),
                    'seconds' => $decodeSeconds,
                    'decoded_at' => now()->toIso8601String(),
                ],
                'new_transcript' => $new->toArray(),
            ],
        ];
    }

    /**
     * The fingerprint the comparison re-takes, so it can refuse a transcript that
     * changed after the decode it is being compared with.
     */
    public static function transcriptHash(ChurchServiceTranscript $transcript): string
    {
        return CanonicalJson::hash($transcript->toArray());
    }

    /**
     * What the stored side already is, so its disagreements are read in their own group:
     * `already_redecoded` was decoded at `max_context=0` after audio banking began, so it
     * measures noise only; `recovered` carries windows re-decoded separately by transcript
     * recovery, which look like decoder differences and are not (1358); `original` is the
     * bulk decode the comparison is about.
     *
     * @return 'already_redecoded'|'recovered'|'original'
     */
    public static function stratum(MediaProcessingLog $run): string
    {
        if (collect(ServiceArtifactStorage::recordedFor($run))->contains('kind', 'audio')) {
            return 'already_redecoded';
        }

        if (str_contains((string) $run->serviceTranscriptPath(), 'recovered') || $run->transcriptRecoveryReplay() !== null) {
            return 'recovered';
        }

        return 'original';
    }

    /** @return array<string, string> */
    private function codeHashes(): array
    {
        $hashes = [];

        foreach (self::DECODE_CODE as $file) {
            $hashes[$file] = (string) hash_file('sha256', base_path($file));
        }

        return $hashes;
    }

    /** @return array{outcome: 'unassessable', reason: string} */
    private function unassessable(string $reason): array
    {
        return ['outcome' => 'unassessable', 'reason' => $reason];
    }
}
