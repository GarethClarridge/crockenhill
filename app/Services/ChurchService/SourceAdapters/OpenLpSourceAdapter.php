<?php

declare(strict_types=1);

namespace App\Services\ChurchService\SourceAdapters;

use App\Data\ChurchServiceSourceRevision;
use App\Data\OpenLpParseResult;
use App\Enums\ChurchServiceEvidenceKind;
use App\Enums\ChurchServiceSource;
use App\Services\ChurchService\ChurchServiceAssertionNormalizer;
use Illuminate\Http\UploadedFile;
use RuntimeException;

class OpenLpSourceAdapter
{
    public function __construct(
        private readonly ChurchServiceAssertionNormalizer $normalizer,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>|null  $resolvedItems
     */
    public function adapt(
        UploadedFile $file,
        OpenLpParseResult $parsed,
        ?array $resolvedItems = null,
    ): ChurchServiceSourceRevision {
        $hash = hash_file('sha256', $file->getRealPath());

        if (! is_string($hash)) {
            throw new RuntimeException('Unable to hash the OpenLP source archive.');
        }

        return new ChurchServiceSourceRevision(
            source: ChurchServiceSource::OpenLp,
            sourceKey: $file->getClientOriginalName(),
            inputHash: $hash,
            assertions: $this->normalizer->normalize($resolvedItems ?? $parsed->items, ChurchServiceEvidenceKind::Planned),
            processingFingerprint: [
                'format' => 'openlp-osz',
                'version' => 1,
            ],
        );
    }
}
