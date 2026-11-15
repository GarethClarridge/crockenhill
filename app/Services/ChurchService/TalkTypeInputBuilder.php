<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Data\ChurchServiceTranscript;
use App\Data\TalkTypeInput;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Support\ServiceArtifactDisk;
use Illuminate\Support\Facades\Storage;

/** Reads persisted sections only. Callers establish the finished structure phase. */
class TalkTypeInputBuilder
{
    /**
     * Locking is only used inside the persistence transaction, never during classification.
     *
     * @param  array<string, mixed>  $versions
     */
    public function build(ServiceSection $section, array $versions = [], bool $lockForUpdate = false): TalkTypeInput
    {
        $runQuery = MediaProcessingLog::query()->whereKey($section->media_processing_log_id);
        $run = ($lockForUpdate ? $runQuery->lockForUpdate() : $runQuery)->firstOrFail();
        $outlineQuery = $run->serviceSections()->orderBy('start_time')->orderBy('section_order')->orderBy('id');
        $sections = ($lockForUpdate ? $outlineQuery->lockForUpdate() : $outlineQuery)->get();
        $current = $sections->firstWhere('id', $section->id);
        $section = $current ?? $section;
        $itemQuery = ChurchServiceItem::withTrashed()->whereKey($section->church_service_item_id);
        $item = ($lockForUpdate ? $itemQuery->lockForUpdate() : $itemQuery)->first();
        $serviceIds = array_values(array_unique(array_filter([$run->church_service_id, $item?->church_service_id])));
        $serviceQuery = ChurchService::query()->whereKey($serviceIds)->orderBy('id');
        $services = ($lockForUpdate ? $serviceQuery->lockForUpdate() : $serviceQuery)->get();
        $identities = $services->map(fn (ChurchService $service): array => ['id' => $service->id, 'date' => $service->date->toDateString(), 'service' => $service->service->value])->all();
        $serviceValues = array_values(array_unique(array_filter([$run->extracted_service?->value, ...array_column($identities, 'service')])));
        $dates = array_values(array_unique(array_filter([$run->extracted_date?->toDateString(), ...array_column($identities, 'date')])));
        $identityConflict = count($serviceValues) > 1 || count($dates) > 1 || count($serviceIds) > 1;
        [$transcript, $transcriptProvenance] = $this->transcript($run);
        $outline = [];
        $outlineIdentity = [];
        $ineligible = [];
        foreach ($sections as $entry) {
            $metadata = $entry->metadata?->toArray() ?? [];
            $text = $transcript?->sliceText($entry->start_time, $entry->end_time) ?? ($metadata['transcript'] ?? null);
            $outline[] = ['section_type' => $entry->section_type->value, 'title' => $entry->title,
                'section_order' => $entry->section_order, 'start' => $entry->start_time, 'end' => $entry->end_time,
                'duration' => $entry->duration, 'text' => $text, 'series' => $metadata['series'] ?? null,
                'role' => $metadata['service_role'] ?? null, 'confidence' => $entry->confidence];
            $outlineIdentity[] = ['id' => $entry->id, 'item_id' => $entry->church_service_item_id,
                'text_hash' => hash('sha256', is_string($text) ? $text : '')];
            if (! is_finite($entry->start_time) || ! is_finite($entry->end_time) || $entry->start_time < 0 || $entry->end_time <= $entry->start_time) {
                $ineligible[] = 'unstable_bounds';
            }
        }
        if ($current === null || ! $section->exists || $section->section_type !== ServiceSectionType::ShortTalk) {
            $ineligible[] = 'not_a_persisted_short_talk';
        }
        if ($run->superseded_at !== null || $run->isExcluded()) {
            $ineligible[] = 'superseded_or_excluded_run';
        }
        $metadata = $section->metadata?->toArray() ?? [];
        $talk = $metadata['talk_type'] ?? [];
        $index = $sections->search(fn (ServiceSection $entry): bool => $entry->id === $section->id);
        $text = $transcript?->sliceText($section->start_time, $section->end_time) ?? ($metadata['transcript'] ?? null);
        $cues = array_values(array_filter($transcript->cues ?? [], fn (array $cue): bool => $cue['end'] > $section->start_time && $cue['start'] < $section->end_time));
        $itemEvidence = $item === null ? null : [
            'title' => $item->title, 'source_title' => $item->source_title, 'type' => $item->type,
            'position' => $item->position, 'source' => $item->source?->value,
            'detection_only' => $item->isAttestedOnlyByDetection(), 'has_normalized_evidence' => $item->hasNormalizedEvidence(),
            'deleted' => $item->trashed(), 'series' => $item->metadata['series'] ?? null,
            'theme' => $item->metadata['theme'] ?? null,
        ];

        return new TalkTypeInput(
            sectionId: $section->id,
            evidence: ['text' => $text, 'title' => $section->title, 'series' => $metadata['series'] ?? null,
                'section_index' => $index, 'item' => $itemEvidence, 'outline' => $outline,
                'service' => $identityConflict ? null : ($serviceValues[0] ?? null), 'identity_conflict' => $identityConflict,
                'transcript' => ['status' => $transcriptProvenance['status'], 'excerpt_only' => $transcript === null,
                    'boundary_overlap' => count(array_filter($cues, fn (array $cue): bool => $cue['start'] < $section->start_time || $cue['end'] > $section->end_time)) > 0,
                    'cues' => $cues, 'unobservable_windows' => $transcript->unobservableWindows ?? []],
                'held_state' => ['needs_manual_review' => $section->needs_manual_review, 'review_flags' => $metadata['review_flags'] ?? [],
                    'content_holds' => $metadata['content_holds'] ?? [],
                    'publication_hold' => $metadata['publication']['hold'] ?? null],
                'limitations' => array_values(array_filter([$item === null ? 'missing_bound_item' : null, $item?->trashed() ? 'deleted_bound_item' : null,
                    $transcript === null ? 'full_transcript_unavailable' : null, $identityConflict ? 'conflicting_service_identity' : null]))],
            provenance: ['run_id' => $run->id, 'section_id' => $section->id, 'item_id' => $item?->id,
                'item_canonical_identity' => $item?->canonical_identity, 'item_evidence' => array_intersect_key($item->metadata ?? [], array_flip(['source_assertion_hashes', 'source_assertion_sources', 'source_evidence', 'livestream_projection'])),
                'service_identities' => $identities, 'run_date' => $run->extracted_date?->toDateString(),
                'outline' => $outlineIdentity, 'transcript' => $transcriptProvenance],
            versions: array_replace($versions, ['input_schema' => 1]),
            structureProposed: array_key_exists('structure_proposed', $talk) ? $talk['structure_proposed'] : ($talk['proposed'] ?? null),
            ineligibleReasons: array_values(array_unique($ineligible)),
        );
    }

    /** @return array{ChurchServiceTranscript|null, array<string, mixed>} */
    private function transcript(MediaProcessingLog $run): array
    {
        $path = $run->serviceTranscriptPath();
        $provenance = ['path' => $path, 'disk' => $path === null ? null : ServiceArtifactDisk::for($path), 'status' => 'not_recorded', 'content_hash' => null];
        if ($path === null) {
            return [null, $provenance];
        }
        try {
            $disk = Storage::disk(ServiceArtifactDisk::for($path));
            if (! $disk->exists($path)) {
                return [null, array_replace($provenance, ['status' => 'missing'])];
            }
            $content = (string) $disk->get($path);
            $provenance['content_hash'] = hash('sha256', $content);
            $transcript = ChurchServiceTranscript::fromArray(json_decode($content, true, 512, JSON_THROW_ON_ERROR));

            return [$transcript, array_replace($provenance, ['status' => $transcript->isEmpty() ? 'empty' : 'available'])];
        } catch (\Throwable) {
            return [null, array_replace($provenance, ['status' => 'unavailable'])];
        }
    }
}
