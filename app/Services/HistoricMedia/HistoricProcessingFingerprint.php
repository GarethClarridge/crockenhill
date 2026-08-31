<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Data\HistoricStagingContext;
use App\Support\CanonicalJson;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The durable-output inputs for an approved historic media batch. Deliberately
 * excludes projector, review, bundle and repository code: those affect a
 * re-projection, not the bytes the media pipeline produces.
 */
final class HistoricProcessingFingerprint
{
    /**
     * The durable fingerprint's schema tag.
     *
     * Deliberately unchanged when `throughput` was dropped: removing a field
     * that never affected the bytes does not change what the fingerprint
     * describes, and bumping it would mark every already-processed run stale and
     * force a reprocess of the corpus to buy nothing.
     */
    private const FINGERPRINT_FORMAT = 'crockenhill.historic-media-processing.v1';

    /**
     * The complete set of inputs that determine the durable media output, and
     * nothing else. Adding a key here is a deliberate statement that changing it
     * should force a reprocess.
     *
     * @var list<string>
     */
    private const PortableKeys = [
        'format',
        // §13.3's "source file hashes", transitively: the manifest hash is taken
        // over normalised entries that carry each file's path, size and SHA-256,
        // so any change to a source file changes this value.
        'source_manifest_hash',
        'transcription',
        'analysis',
        'ffmpeg',
        'service_structure',
        'segmentation',
        'section_classification',
    ];

    /** @return array<string, mixed> */
    public function forStagingContext(HistoricStagingContext $context): array
    {
        return [
            'format' => self::FINGERPRINT_FORMAT,
            'source_manifest_hash' => $context->manifestHash,
            'transcription' => [
                'service' => config('media-processing.transcription.service'),
                'local_whisper_model' => config('media-processing.transcription.local_whisper_model'),
                'service_transcription_service' => config('media-processing.service_structure.transcription_service'),
                'service_transcription_model' => config('media-processing.service_structure.transcription_model'),
                'prompts' => [
                    'sermon_hash' => CanonicalJson::hash(config('media-processing.transcription.prompts.sermon')),
                    'full_service_hash' => CanonicalJson::hash(config('media-processing.transcription.prompts.full_service')),
                ],
            ],
            'analysis' => [
                'service' => config('media-processing.analysis.service'),
                'model' => config('media-processing.analysis.model'),
                'reasoning_effort' => config('media-processing.analysis.reasoning_effort'),
            ],
            'ffmpeg' => [
                'ffmpeg_binary' => $this->binaryEvidence((string) config('media-processing.ffmpeg.ffmpeg_path')),
                'ffprobe_binary' => $this->binaryEvidence((string) config('media-processing.ffmpeg.ffprobe_path')),
                'historic_concat_arguments' => [
                    'lossless' => ['-f', 'concat', '-safe', '0', '-c', 'copy'],
                    'reencoded' => ['-filter_complex', 'concat', '-c:v', 'libx264', '-preset', 'veryfast', '-c:a', 'aac', '-b:a', '192k'],
                ],
                'audio_enhancement' => config('media-processing.audio_enhancement'),
            ],
            'service_structure' => config('media-processing.service_structure'),
            'segmentation' => config('media-processing.segmentation'),
            'section_classification' => config('media-processing.section_classification'),
        ];
    }

    /** @param array<string, mixed> $fingerprint */
    public function assertMatchesCurrentConfiguration(HistoricStagingContext $context, array $fingerprint): void
    {
        $fingerprint = $this->normalize($fingerprint);
        $current = $this->normalize($this->forStagingContext($context));

        if (CanonicalJson::hash($fingerprint) !== CanonicalJson::hash($current)) {
            throw new RuntimeException('Historic processing fingerprint does not match the approved media configuration and source manifest.');
        }
    }

    /**
     * Return the one canonical durable fingerprint representation.
     *
     * Earlier runs recorded queue widths under `throughput`. That field was
     * execution evidence, not a byte-affecting input, so it is tolerated on a
     * recognised fingerprint and stripped before comparison or export; where it
     * appears on an unrecognised `format` the value cannot be interpreted at all
     * and is refused. Every other unknown key remains a hard failure, which is
     * what keeps this a normalisation of one known field rather than a general
     * licence to ignore fields.
     *
     * Note this is not a version gate: the format tag is unchanged, so tolerating
     * `throughput` is a property of the field, not of an older schema. The
     * narrowing that matters is the allowlist below.
     *
     * @param  array<string, mixed>  $fingerprint
     * @return array<string, mixed>
     */
    public function normalize(array $fingerprint): array
    {
        if (array_key_exists('throughput', $fingerprint)) {
            if (($fingerprint['format'] ?? null) !== self::FINGERPRINT_FORMAT) {
                throw new RuntimeException(
                    'Historic processing fingerprint may carry legacy throughput only on a recognised fingerprint format.'
                );
            }

            unset($fingerprint['throughput']);
        }

        $unknown = array_diff(array_keys($fingerprint), self::PortableKeys);

        if ($unknown !== []) {
            throw new RuntimeException(
                'Historic processing fingerprint must not pin non-media input: '.implode(', ', $unknown).'. '
                .'Projector, review, bundle, export and auditor code are covered by the projection policy version instead.'
            );
        }

        return $fingerprint;
    }

    /**
     * The top level is an allowlist rather than a denylist of suspicious words.
     * A fingerprint that pinned the git commit would mark correctly-processed
     * media stale on any unrelated change — a projector fix, a review-UI tweak —
     * and under the §9.4 loop that means reprocessing the corpus per iteration.
     * Naming what may appear is the only way to keep that closed; a substring
     * rule both misses new spellings and fires on innocent keys.
     *
     * @param  array<string, mixed>  $fingerprint
     */
    public function assertPortable(array $fingerprint): void
    {
        $this->normalize($fingerprint);
    }

    /** @return array{sha256: string, version: string} */
    private function binaryEvidence(string $path): array
    {
        if ($path === '' || ! is_file($path) || ! is_executable($path)) {
            throw new RuntimeException("Historic processing binary is missing or not executable: {$path}.");
        }

        $sha256 = hash_file('sha256', $path);
        $process = new Process([$path, '-version']);
        $process->setTimeout(10);
        $process->run();
        $firstLine = strtok(trim($process->getOutput()), "\n");

        if (! is_string($sha256) || ! $process->isSuccessful() || ! is_string($firstLine)) {
            throw new RuntimeException("Historic processing binary version could not be verified: {$path}.");
        }

        return ['sha256' => $sha256, 'version' => $firstLine];
    }
}
