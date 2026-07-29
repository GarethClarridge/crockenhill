<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Data\ChurchServiceProjection;
use App\Enums\ChurchServiceEvidenceKind;
use App\Enums\ChurchServiceOccurrenceState;
use App\Enums\ChurchServiceSource;
use App\Models\ChurchServiceItemAssertion;
use App\Models\ChurchServiceSourceRecord;
use App\Support\CanonicalJson;
use Illuminate\Support\Collection;

class ChurchServiceProjector
{
    /**
     * @param  Collection<int, ChurchServiceSourceRecord>  $sourceRecords
     */
    public function project(Collection $sourceRecords): ChurchServiceProjection
    {
        $activeRecords = $this->activeRecords($sourceRecords);
        $manualRecord = $activeRecords
            ->where('source', ChurchServiceSource::Manual)
            ->sortByDesc(fn (ChurchServiceSourceRecord $record): string => $this->recordOrder($record))
            ->first();

        $projectionRecords = $manualRecord instanceof ChurchServiceSourceRecord
            ? collect([$manualRecord])
            : $activeRecords->where('source', '!=', ChurchServiceSource::Manual)->values();

        $groups = $this->matchedAssertionGroups($projectionRecords);
        $items = $groups
            ->map(fn (Collection $assertions): array => $this->projectItem($assertions))
            ->sort($this->compareItems(...))
            ->values()
            ->map(function (array $item, int $index): array {
                $item['position'] = $index + 1;
                unset($item['_order']);

                return $item;
            })
            ->all();
        $items = array_values($items);

        $serviceContent = $this->projectServiceContent($activeRecords, $manualRecord);
        $sourceSummary = $manualRecord instanceof ChurchServiceSourceRecord
            ? ChurchServiceSource::Manual->value
            : $this->machineSourceSummary($activeRecords);
        $hash = CanonicalJson::hash([
            'items' => $items,
            'service_content' => $serviceContent,
        ]);

        return new ChurchServiceProjection(
            items: $items,
            serviceContent: $serviceContent,
            sourceSummary: $sourceSummary,
            hash: $hash,
        );
    }

    /**
     * Keep only the newest revision for each stable source key. Revision identity,
     * rather than arrival order, determines the active set.
     *
     * @param  Collection<int, ChurchServiceSourceRecord>  $records
     * @return Collection<int, ChurchServiceSourceRecord>
     */
    private function activeRecords(Collection $records): Collection
    {
        return $records
            ->sortBy(fn (ChurchServiceSourceRecord $record): string => $this->recordOrder($record))
            ->groupBy(fn (ChurchServiceSourceRecord $record): string => "{$record->source->value}\0{$record->source_key}")
            ->map(fn (Collection $revisions): ChurchServiceSourceRecord => $revisions->reverse()->firstOrFail())
            ->sortBy(fn (ChurchServiceSourceRecord $record): string => "{$record->source->value}\0{$record->source_key}")
            ->values();
    }

    private function recordOrder(ChurchServiceSourceRecord $record): string
    {
        return ($record->captured_at?->format('Y-m-d\TH:i:s.u') ?? '')."\0{$record->revision_hash}";
    }

    /**
     * @param  Collection<int, ChurchServiceSourceRecord>  $records
     * @return Collection<string, Collection<int, ChurchServiceItemAssertion>>
     */
    private function matchedAssertionGroups(Collection $records): Collection
    {
        /** @var Collection<int, ChurchServiceItemAssertion> $assertions */
        $assertions = $records
            ->flatMap(fn (ChurchServiceSourceRecord $record): Collection => $record->assertions)
            ->sortBy(fn (ChurchServiceItemAssertion $assertion): string => $this->assertionOrder($assertion))
            ->values();

        return $assertions->groupBy(fn (ChurchServiceItemAssertion $assertion): string => $this->canonicalIdentity($assertion));
    }

    private function canonicalIdentity(ChurchServiceItemAssertion $assertion): string
    {
        if (filled($assertion->song_canonical_key)) {
            return 'song:'.mb_strtolower((string) $assertion->song_canonical_key);
        }

        if ($assertion->song_id !== null) {
            return "song-id:{$assertion->song_id}";
        }

        if (filled($assertion->normalized_scripture_key)) {
            return 'scripture:'.mb_strtolower((string) $assertion->normalized_scripture_key);
        }

        if (filled($assertion->scripture_reference)) {
            return 'scripture:'.mb_strtolower((string) $assertion->scripture_reference);
        }

        return mb_strtolower("{$assertion->type}:{$assertion->normalized_title}");
    }

    private function assertionOrder(ChurchServiceItemAssertion $assertion): string
    {
        $record = $assertion->sourceRecord;

        return "{$record->source->value}\0{$record->source_key}\0".
            str_pad((string) $assertion->source_position, 10, '0', STR_PAD_LEFT).
            "\0{$assertion->assertion_key}";
    }

    /**
     * @param  Collection<int, ChurchServiceItemAssertion>  $assertions
     * @return array<string, mixed>
     */
    private function projectItem(Collection $assertions): array
    {
        $selected = $assertions
            ->sortBy(fn (ChurchServiceItemAssertion $assertion): string => $this->fieldAuthority($assertion))
            ->firstOrFail();
        $planned = $assertions->contains(
            fn (ChurchServiceItemAssertion $assertion): bool => $assertion->evidence_kind === ChurchServiceEvidenceKind::Planned,
        );
        $observed = $assertions->contains(
            fn (ChurchServiceItemAssertion $assertion): bool => $assertion->evidence_kind === ChurchServiceEvidenceKind::Observed,
        );
        $manual = $assertions->contains(
            fn (ChurchServiceItemAssertion $assertion): bool => $assertion->evidence_kind === ChurchServiceEvidenceKind::Manual,
        );
        $observedAssertion = $assertions
            ->filter(fn (ChurchServiceItemAssertion $assertion): bool => $assertion->evidence_kind === ChurchServiceEvidenceKind::Observed)
            ->sortBy(fn (ChurchServiceItemAssertion $assertion): string => $this->assertionOrder($assertion))
            ->first();
        $selectedMetadata = $selected->metadata ?? [];
        $observedMetadata = $observedAssertion instanceof ChurchServiceItemAssertion
            ? ($observedAssertion->metadata ?? [])
            : [];

        return [
            'canonical_identity' => $this->canonicalIdentity($selected),
            'type' => $selected->type,
            'section_type' => $selected->section_type?->value,
            'source' => $selected->sourceRecord->source->value,
            'title' => $selected->title,
            'source_title' => $selected->source_title,
            'openlp_search_title' => $this->openLpSearchTitle($assertions),
            'song_id' => $this->authoritativeSongId($assertions),
            'occurrence_state' => match (true) {
                $manual => ChurchServiceOccurrenceState::ManuallyConfirmed->value,
                $planned && $observed => ChurchServiceOccurrenceState::PlannedAndObserved->value,
                $observed => ChurchServiceOccurrenceState::ObservedOnly->value,
                default => ChurchServiceOccurrenceState::PlannedOnly->value,
            },
            'manual_occurrence_decision' => $manual ? true : null,
            'livestream_processing_id' => $observedMetadata['livestream_processing_id'] ?? null,
            'livestream_service_section_id' => $observedMetadata['livestream_service_section_id'] ?? null,
            'metadata' => [
                ...$selectedMetadata,
                ...$observedMetadata,
                'source_assertion_hashes' => $assertions
                    ->map(fn (ChurchServiceItemAssertion $assertion): string => $assertion->sourceRecord->revision_hash.':'.$assertion->assertion_key)
                    ->sort()
                    ->values()
                    ->all(),
            ],
            '_order' => $this->itemOrder($assertions),
        ];
    }

    private function fieldAuthority(ChurchServiceItemAssertion $assertion): string
    {
        $isSong = $assertion->song_id !== null
            || filled($assertion->song_canonical_key)
            || mb_strtolower($assertion->type) === 'songs';
        $rank = match ($assertion->sourceRecord->source) {
            ChurchServiceSource::Manual => 0,
            ChurchServiceSource::OpenLp => $isSong ? 1 : 2,
            ChurchServiceSource::Email => $isSong ? 2 : 1,
            ChurchServiceSource::Livestream => 3,
        };

        return "{$rank}\0".$this->assertionOrder($assertion);
    }

    /**
     * @param  Collection<int, ChurchServiceItemAssertion>  $assertions
     */
    private function authoritativeSongId(Collection $assertions): ?int
    {
        return $assertions
            ->filter(fn (ChurchServiceItemAssertion $assertion): bool => $assertion->song_id !== null)
            ->sortBy(fn (ChurchServiceItemAssertion $assertion): string => $this->fieldAuthority($assertion))
            ->first()?->song_id;
    }

    /**
     * @param  Collection<int, ChurchServiceItemAssertion>  $assertions
     */
    private function openLpSearchTitle(Collection $assertions): ?string
    {
        $openLp = $assertions->first(
            fn (ChurchServiceItemAssertion $assertion): bool => $assertion->sourceRecord->source === ChurchServiceSource::OpenLp,
        );

        if (! $openLp instanceof ChurchServiceItemAssertion) {
            return null;
        }

        return $openLp->metadata['openlp_search_title']
            ?? $openLp->source_title
            ?? $openLp->title;
    }

    /**
     * @param  Collection<int, ChurchServiceItemAssertion>  $assertions
     * @return array{manual: int, observed: float, openlp: int, email: int, livestream: int, identity: string}
     */
    private function itemOrder(Collection $assertions): array
    {
        $position = function (ChurchServiceSource $source) use ($assertions): int {
            $positions = $assertions
                ->filter(fn (ChurchServiceItemAssertion $assertion): bool => $assertion->sourceRecord->source === $source)
                ->pluck('source_position');

            return $positions->isEmpty() ? PHP_INT_MAX : (int) $positions->min();
        };
        $observedStarts = $assertions
            ->filter(fn (ChurchServiceItemAssertion $assertion): bool => $assertion->evidence_kind === ChurchServiceEvidenceKind::Observed)
            ->pluck('start_seconds')
            ->filter(fn (mixed $value): bool => $value !== null);

        return [
            'manual' => $position(ChurchServiceSource::Manual),
            'observed' => $observedStarts->isEmpty() ? INF : (float) $observedStarts->min(),
            'openlp' => $position(ChurchServiceSource::OpenLp),
            'email' => $position(ChurchServiceSource::Email),
            'livestream' => $position(ChurchServiceSource::Livestream),
            'identity' => $this->canonicalIdentity($assertions->firstOrFail()),
        ];
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function compareItems(array $left, array $right): int
    {
        foreach (['manual', 'observed', 'openlp', 'email', 'livestream', 'identity'] as $field) {
            $comparison = $left['_order'][$field] <=> $right['_order'][$field];

            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return 0;
    }

    /**
     * @param  Collection<int, ChurchServiceSourceRecord>  $activeRecords
     * @return array{summary: mixed, notices: mixed, chapter_markers: mixed}
     */
    private function projectServiceContent(
        Collection $activeRecords,
        ?ChurchServiceSourceRecord $manualRecord,
    ): array {
        $contentRecord = $manualRecord;

        if (! $contentRecord instanceof ChurchServiceSourceRecord) {
            $contentRecord = $activeRecords
                ->where('source', ChurchServiceSource::Livestream)
                ->filter(fn (ChurchServiceSourceRecord $record): bool => is_array($record->service_content))
                ->sortByDesc(fn (ChurchServiceSourceRecord $record): string => $this->recordOrder($record))
                ->first();
        }

        $content = $contentRecord instanceof ChurchServiceSourceRecord
            ? ($contentRecord->service_content ?? [])
            : [];

        return [
            'summary' => $content['summary'] ?? null,
            'notices' => $content['notices'] ?? null,
            'chapter_markers' => $content['chapter_markers'] ?? null,
        ];
    }

    /**
     * @param  Collection<int, ChurchServiceSourceRecord>  $activeRecords
     */
    private function machineSourceSummary(Collection $activeRecords): string
    {
        $sources = $activeRecords
            ->pluck('source')
            ->reject(fn (ChurchServiceSource $source): bool => $source === ChurchServiceSource::Manual)
            ->unique(fn (ChurchServiceSource $source): string => $source->value)
            ->values();

        return $sources->count() === 1
            ? $sources->first()->value
            : 'mixed';
    }
}
