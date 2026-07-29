<?php

declare(strict_types=1);

namespace App\Services\ChurchService\SourceAdapters;

use App\Data\ChurchServiceSourceRevision;
use App\Enums\ChurchServiceEvidenceKind;
use App\Enums\ChurchServiceSource;
use App\Models\MediaProcessingLog;
use App\Services\ChurchService\ChurchServiceAssertionNormalizer;
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
            ],
            serviceContent: $serviceContent,
            capturedAt: $processingLog->completed_at ?? $processingLog->updated_at ?? now(),
        );
    }

    /**
     * Build the same Livestream evidence payload from a verified portable bundle.
     *
     * This method only packages the already-normalized bundle assertions. It
     * deliberately performs no projection, media processing, job dispatch or
     * external call.
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
