<?php

declare(strict_types=1);

namespace App\Services\ChurchService\SourceAdapters;

use App\Data\ChurchServiceSourceRevision;
use App\Enums\ChurchServiceEvidenceKind;
use App\Enums\ChurchServiceSource;
use App\Models\MediaProcessingLog;
use App\Services\ChurchService\ChurchServiceAssertionNormalizer;
use App\Services\ChurchService\ChurchServiceProjector;
use App\Support\CanonicalJson;

class LivestreamSourceAdapter
{
    public const int FORMAT_VERSION = 1;

    public function __construct(
        private readonly ChurchServiceAssertionNormalizer $normalizer,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>|null  $serviceContent
     */
    public function adapt(
        MediaProcessingLog $processingLog,
        array $items,
        ?array $serviceContent,
    ): ChurchServiceSourceRevision {
        $assertions = $this->normalizer->normalize($items, ChurchServiceEvidenceKind::Observed);

        return new ChurchServiceSourceRevision(
            source: ChurchServiceSource::Livestream,
            sourceKey: $processingLog->processing_id.'|v'.self::FORMAT_VERSION,
            inputHash: CanonicalJson::hash([
                'assertions' => $assertions,
                'service_content' => $serviceContent,
            ]),
            assertions: $assertions,
            processingFingerprint: [
                'format' => 'livestream-projection',
                'version' => self::FORMAT_VERSION,
                'processing_id' => $processingLog->processing_id,
                ...$this->historicCorroborationFingerprint($processingLog),
            ],
            serviceContent: $serviceContent,
            capturedAt: $processingLog->completed_at ?? $processingLog->updated_at ?? now(),
        );
    }

    /**
     * The historic completeness facts {@see ChurchServiceProjector}
     * needs to decide what this recording may corroborate (plan §2.5, §0.1 slice 3).
     *
     * Two keys, and both matter:
     *
     * - `historic_import` marks the revision as coming from the archive rather than the weekly
     *   upload. It is derived here from the processing log itself, not accepted from a caller, so a
     *   historic recording cannot reach the projector looking ordinary. An ordinary weekly
     *   livestream gets neither key and is unaffected by any of this.
     * - `corroboration_grade` is the operator-approved grade carried down from the manifest. It is
     *   deliberately allowed to be null: a historic recording whose grade never arrived must be
     *   treated as unproven, and a null the projector fails closed on is safer than a default.
     *
     * These are provenance fields. The revision's `inputHash` is computed from assertions and
     * service content only, so recording them changes no content hash and cannot invalidate an
     * approved proposal or an exported bundle.
     *
     * @return array<string, mixed>
     */
    private function historicCorroborationFingerprint(MediaProcessingLog $processingLog): array
    {
        $metadata = $processingLog->processing_metadata?->toArray() ?? [];
        $historic = $metadata['historic_import'] ?? null;

        if (! is_array($historic)) {
            return [];
        }

        $grade = $historic['corroboration_grade'] ?? null;

        return [
            'historic_import' => true,
            'corroboration_grade' => is_string($grade) ? $grade : null,
            'transcript_unobservable_windows' => $processingLog->serviceTranscriptUnobservableWindows(),
        ];
    }

    /**
     * Build the same Livestream evidence payload from a verified portable bundle.
     *
     * This method only packages the already-normalized bundle assertions. It
     * deliberately performs no projection, media processing, job dispatch or
     * external call.
     *
     * **Unwired as of 2026-08-25, and it carries a trap for whoever wires it.** Unlike
     * {@see self::adapt()}, this takes `$processingFingerprint` from its caller rather than deriving
     * it from a processing log, so it cannot mark a historic revision itself. A bundle exported for
     * the video lane MUST carry `historic_import` and `corroboration_grade` through this argument.
     * A historic recording arriving without them is indistinguishable from a weekly upload and
     * {@see ChurchServiceProjector::sourceProvesDimension()} will trust it to corroborate song
     * membership — which is precisely the sermon-only-clip hazard §0.1 slice 3 exists to close.
     * Bind the export to the same two keys before using this, and cover it with a test.
     *
     * @param  list<array<string, mixed>>  $assertions
     * @param  array<string, mixed>|null  $serviceContent
     * @param  array<string, mixed>  $processingFingerprint
     */
    public function adaptPortable(
        string $processingUuid,
        string $inputHash,
        array $assertions,
        ?array $serviceContent,
        array $processingFingerprint,
        ?string $batchHash = null,
    ): ChurchServiceSourceRevision {
        return new ChurchServiceSourceRevision(
            source: ChurchServiceSource::Livestream,
            sourceKey: $processingUuid.'|v'.self::FORMAT_VERSION,
            inputHash: $inputHash,
            assertions: $assertions,
            processingFingerprint: $processingFingerprint,
            serviceContent: $serviceContent,
            batchHash: $batchHash,
        );
    }
}
