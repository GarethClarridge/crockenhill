<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Data\HistoricStagingContext;
use App\Support\CanonicalJson;
use RuntimeException;

/**
 * The durable-output inputs for an approved historic media batch. Deliberately
 * excludes projector, review, bundle and repository code: those affect a
 * re-projection, not the bytes the media pipeline produces.
 */
final class HistoricProcessingFingerprint
{
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
        'throughput',
    ];

    public function __construct(
        private readonly HistoricProcessingThroughput $throughput,
    ) {}

    /** @return array<string, mixed> */
    public function forStagingContext(HistoricStagingContext $context): array
    {
        return [
            'format' => 'crockenhill.historic-media-processing.v1',
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
                'ffmpeg_binary_fingerprint' => hash('sha256', (string) config('media-processing.ffmpeg.ffmpeg_path')),
                'ffprobe_binary_fingerprint' => hash('sha256', (string) config('media-processing.ffmpeg.ffprobe_path')),
                'audio_enhancement' => config('media-processing.audio_enhancement'),
            ],
            'service_structure' => config('media-processing.service_structure'),
            'segmentation' => config('media-processing.segmentation'),
            'section_classification' => config('media-processing.section_classification'),
            'throughput' => $this->throughput->fingerprint(),
        ];
    }

    /** @param array<string, mixed> $fingerprint */
    public function assertMatchesCurrentConfiguration(HistoricStagingContext $context, array $fingerprint): void
    {
        $this->assertPortable($fingerprint);

        if (CanonicalJson::hash($fingerprint) !== CanonicalJson::hash($this->forStagingContext($context))) {
            throw new RuntimeException('Historic processing fingerprint does not match the approved media configuration and source manifest.');
        }
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
        $unknown = array_diff(array_keys($fingerprint), self::PortableKeys);

        if ($unknown !== []) {
            throw new RuntimeException(
                'Historic processing fingerprint must not pin non-media input: '.implode(', ', $unknown).'. '
                .'Projector, review, bundle, export and auditor code are covered by the projection policy version instead.'
            );
        }
    }
}
