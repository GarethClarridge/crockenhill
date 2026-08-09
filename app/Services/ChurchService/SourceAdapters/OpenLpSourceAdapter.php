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

    public function adapt(
        UploadedFile $file,
        OpenLpParseResult $parsed,
        ?string $batchHash = null,
        ?string $approvedInputHash = null,
    ): ChurchServiceSourceRevision {
        $hash = hash_file('sha256', $file->getRealPath());

        if (! is_string($hash)) {
            throw new RuntimeException('Unable to hash the OpenLP source archive.');
        }

        if ($approvedInputHash !== null && ! hash_equals($approvedInputHash, $hash)) {
            throw new RuntimeException('The OpenLP archive no longer matches its approved source snapshot.');
        }

        return new ChurchServiceSourceRevision(
            source: ChurchServiceSource::OpenLp,
            sourceKey: $file->getClientOriginalName(),
            inputHash: $approvedInputHash ?? $hash,
            assertions: $this->normalizer->normalize($parsed->items, ChurchServiceEvidenceKind::Planned),
            processingFingerprint: [
                'format' => 'openlp-osz',
                'version' => 1,
            ],
            batchHash: $batchHash,
        );
    }
}
