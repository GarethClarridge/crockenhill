<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Data\ServiceSectionMetadata;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SongContinuationMerger
{
    public function __construct(
        private readonly ServiceSectionSyncService $syncService,
    ) {}

    /**
     * @return list<array{anchor: ServiceSection, absorbed: EloquentCollection<int, ServiceSection>}>
     */
    public function preview(MediaProcessingLog $processingLog, bool $conservative = true): array
    {
        $sections = $processingLog->serviceSections()
            ->orderBy('section_order')
            ->orderBy('id')
            ->get()
            ->all();

        $groups = [];
        $count = count($sections);

        for ($index = 0; $index < $count; $index++) {
            $anchor = $sections[$index];

            if (! $this->isAnchor($anchor)) {
                continue;
            }

            $absorbed = [];
            $previous = $anchor;

            for ($candidateIndex = $index + 1; $candidateIndex < $count; $candidateIndex++) {
                $candidate = $sections[$candidateIndex];

                if (! $this->canAbsorb($anchor, $previous, $candidate, $conservative)) {
                    break;
                }

                $absorbed[] = $candidate;
                $previous = $candidate;
                $index = $candidateIndex;
            }

            if ($absorbed !== []) {
                $groups[] = ['anchor' => $anchor, 'absorbed' => new EloquentCollection($absorbed)];
            }
        }

        return $groups;
    }

    /**
     * @param  array{anchor: ServiceSection, absorbed: EloquentCollection<int, ServiceSection>}  $group
     */
    public function apply(array $group, string $source = 'pipeline'): void
    {
        $sectionIds = array_merge([$group['anchor']->id], $group['absorbed']->pluck('id')->all());

        DB::transaction(function () use ($sectionIds, $source): void {
            /** @var EloquentCollection<int, ServiceSection> $sections */
            $sections = ServiceSection::query()
                ->whereIn('id', $sectionIds)
                ->orderBy('section_order')
                ->lockForUpdate()
                ->get();

            $anchor = $sections->first();
            if (! $anchor instanceof ServiceSection || ! $this->isAnchor($anchor) || $sections->count() < 2) {
                return;
            }

            $absorbed = $sections->slice(1);
            $metadata = $anchor->metadata?->toArray() ?? [];
            $metadata['song_continuation_merge'] = [
                'source' => $source,
                'merged_at' => now()->toIso8601String(),
                'absorbed_section_ids' => $absorbed->pluck('id')->values()->all(),
            ];

            $startTime = (float) $anchor->start_time;
            $endTime = (float) $anchor->end_time;
            foreach ($absorbed as $section) {
                $startTime = min($startTime, (float) $section->start_time);
                $endTime = max($endTime, (float) $section->end_time);
            }

            $anchor->start_time = $startTime;
            $anchor->end_time = $endTime;
            $anchor->duration = max(0.0, (float) $anchor->end_time - (float) $anchor->start_time);
            $anchor->source_segment_ids = $sections
                ->flatMap(fn (ServiceSection $section): array => $section->source_segment_ids)
                ->unique()
                ->values()
                ->all();
            $anchor->needs_manual_review = $sections->contains(
                fn (ServiceSection $section): bool => $section->needs_manual_review
            );
            $anchor->extracted_video_path = null;
            $anchor->extracted_audio_path = null;
            $anchor->extracted_at = null;
            $anchor->publication_status = ServiceSectionPublicationStatus::NotApplicable;
            $anchor->metadata = ServiceSectionMetadata::fromArray($metadata);
            $anchor->save();

            foreach ($absorbed as $section) {
                $this->syncService->removeSection($section);
            }
        });
    }

    public function merge(MediaProcessingLog $processingLog, bool $conservative = true, string $source = 'pipeline'): int
    {
        $groups = $this->preview($processingLog, $conservative);

        foreach ($groups as $group) {
            $this->apply($group, $source);
        }

        return count($groups);
    }

    private function isAnchor(ServiceSection $section): bool
    {
        return $section->section_type === ServiceSectionType::Song
            && in_array($section->song_match_type, [
                ServiceSectionSongMatchType::Confirmed,
                ServiceSectionSongMatchType::Inferred,
            ], true);
    }

    private function canAbsorb(
        ServiceSection $anchor,
        ServiceSection $previous,
        ServiceSection $candidate,
        bool $conservative,
    ): bool {
        $gap = (float) $candidate->start_time - (float) $previous->end_time;
        $configuredMaxGap = (float) config('media-processing.section_classification.adjacent_merge_max_gap_seconds', 2);
        $maxGap = $conservative ? $configuredMaxGap : max($configuredMaxGap, 5.0);

        if ($gap > $maxGap) {
            return false;
        }

        if ($candidate->section_type === ServiceSectionType::Song) {
            $candidateSongId = $candidate->metadata?->songId;
            $anchorSongId = $anchor->metadata?->songId;

            if ($candidate->church_service_item_id !== null
                && $candidate->church_service_item_id !== $anchor->church_service_item_id
                && ($conservative || $candidateSongId === null || $candidateSongId !== $anchorSongId)) {
                return false;
            }

            return $candidateSongId === null || $candidateSongId === $anchorSongId;
        }

        if ($candidate->section_type !== ServiceSectionType::Other
            || $candidate->church_service_item_id !== null
            || ! $this->isLowConfidence($candidate)) {
            return false;
        }

        if ($this->hasExplicitTailSignal($candidate)) {
            return true;
        }

        return ! $conservative && $this->looksLikeLyricContinuation($candidate);
    }

    private function isLowConfidence(ServiceSection $section): bool
    {
        return $section->metadata?->confidenceLevel === 'low'
            || ($section->confidence !== null && (float) $section->confidence < 0.75);
    }

    private function hasExplicitTailSignal(ServiceSection $section): bool
    {
        $metadata = $section->metadata?->toArray() ?? [];
        $notes = is_array($metadata['ai_notes'] ?? null) ? $metadata['ai_notes'] : [];
        $signals = array_filter([
            $section->summary,
            $metadata['summary'] ?? null,
            ...$notes,
        ], is_string(...));
        $text = Str::lower(implode(' ', $signals));

        return Str::contains($text, [
            'song tail',
            'tail end of a song',
            'tail end of the song',
            'instrumental tail',
            'song spilling',
            'lyric tail',
            'sung/lyrical',
            'sung or lyrical',
        ]);
    }

    private function looksLikeLyricContinuation(ServiceSection $section): bool
    {
        $transcript = $section->metadata?->transcript;

        if (! is_string($transcript) || trim($transcript) === '') {
            return false;
        }

        return ! Str::contains(Str::lower($transcript), [
            'let us pray',
            "let's pray",
            'turn in your bibles',
            'our reading',
            'the notices',
        ]);
    }
}
