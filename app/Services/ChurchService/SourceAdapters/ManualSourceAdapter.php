<?php

declare(strict_types=1);

namespace App\Services\ChurchService\SourceAdapters;

use App\Data\ChurchServiceSourceRevision;
use App\Enums\ChurchServiceEvidenceKind;
use App\Enums\ChurchServiceSource;
use App\Services\ChurchService\ChurchServiceAssertionNormalizer;
use App\Support\CanonicalJson;
use Illuminate\Support\Str;

class ManualSourceAdapter
{
    public function __construct(
        private readonly ChurchServiceAssertionNormalizer $normalizer,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>|null  $serviceContent
     */
    public function adapt(
        array $items,
        int $userId,
        ?array $serviceContent = null,
        ?string $reviewUuid = null,
    ): ChurchServiceSourceRevision {
        $assertions = $this->normalizer->normalize($items, ChurchServiceEvidenceKind::Manual);

        return new ChurchServiceSourceRevision(
            source: ChurchServiceSource::Manual,
            sourceKey: $reviewUuid ?? (string) Str::uuid(),
            inputHash: CanonicalJson::hash([
                'assertions' => $assertions,
                'service_content' => $serviceContent,
            ]),
            assertions: $assertions,
            processingFingerprint: [
                'format' => 'manual-review',
                'version' => 1,
            ],
            serviceContent: $serviceContent,
            createdByUserId: $userId,
        );
    }
}
