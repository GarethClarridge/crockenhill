<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Enums\ChurchServiceSource;
use App\Models\ChurchServiceSourceRecord;
use App\Support\CanonicalJson;
use Illuminate\Support\Collection;

/**
 * The single definition of "which machine evidence is this service standing on".
 *
 * Exporter, auditor and importer all compare this hash across databases, so it
 * has to be derived in exactly one place. When it lived in three, a change to
 * the active-leaf rule on one side silently classified matching evidence as a
 * difference on another.
 */
class ChurchServiceEvidenceSet
{
    public function __construct(
        private readonly ChurchServiceProjector $projector,
    ) {}

    /**
     * @param  Collection<int, ChurchServiceSourceRecord>  $sourceRecords  every loaded revision, superseded ones included
     */
    public function hash(Collection $sourceRecords): string
    {
        return CanonicalJson::hash($this->records($sourceRecords)
            ->map(fn (ChurchServiceSourceRecord $record): array => [
                'source' => $record->source->value,
                'source_key' => $record->source_key,
                'revision_hash' => $record->revision_hash,
                'processing_fingerprint' => $record->processing_fingerprint,
            ])
            ->values()
            ->all());
    }

    /**
     * @param  Collection<int, ChurchServiceSourceRecord>  $sourceRecords
     * @return Collection<int, ChurchServiceSourceRecord>
     */
    public function records(Collection $sourceRecords): Collection
    {
        return $this->projector->activeSourceRecords($sourceRecords)
            ->filter(fn (ChurchServiceSourceRecord $record): bool => $record->source !== ChurchServiceSource::Manual)
            ->sortBy(fn (ChurchServiceSourceRecord $record): string => (string) $record->revision_hash)
            ->values();
    }
}
