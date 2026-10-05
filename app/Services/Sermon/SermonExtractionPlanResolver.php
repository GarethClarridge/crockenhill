<?php

declare(strict_types=1);

namespace App\Services\Sermon;

use App\Actions\HoldSectionForContentReview;
use App\Data\ChurchServiceTranscript;
use App\Data\ServiceSectionMetadata;
use App\Enums\ServiceSectionType;
use App\Exceptions\OutputEdgeTimingsMissing;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\CueSafeExtractionPlan;
use App\Services\ChurchService\Structure\SermonContinuationScreen;
use App\Services\Media\Audio\AudioTimeline;
use App\Services\Scripture\ScriptureReferenceResolver;
use App\Support\SermonAutoExtractionPolicy;
use App\Support\ServiceArtifactDisk;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/** Composes named sections upstream; execution resolves their current bounds without fallback. */
class SermonExtractionPlanResolver
{
    public function __construct(
        private readonly ScriptureReferenceResolver $scriptureReferences,
        private readonly SermonContinuationScreen $continuations,
    ) {}

    /**
     * Recompute composition after detection or review. Keep a review decision only while
     * its section and evidence identity remains unchanged.
     *
     * @return array<string, mixed>
     */
    public function compose(MediaProcessingLog $log): array
    {
        $sections = $log->serviceSections()->orderBy('start_time')->orderBy('id')->get()->values()->all();
        $identity = $this->inputIdentity($log, $sections);
        $sermons = array_values(array_filter($sections, static fn (ServiceSection $section): bool => $section->section_type === ServiceSectionType::Sermon));
        $risks = [];
        $pendingEdgeEvidence = null;
        $selected = [];
        $sermon = count($sermons) === 1 ? $sermons[0] : null;
        if ($sermon === null) {
            $risks[] = ['kind' => 'sermon_membership_unresolved', 'detail' => 'Identify one sermon section before extraction.'];
        } else {
            $selected[] = $sermon;
            foreach ($sections as $section) {
                if ($section->id !== $sermon->id && $this->continues($section, $sermon->id)) {
                    // An embedded continuation is already present; a partial overlap is invalid.
                    if ($section->start_time >= $sermon->start_time && $section->end_time <= $sermon->end_time) {
                        continue;
                    }
                    $selected[] = $section;
                }
            }
            usort($selected, static fn (ServiceSection $a, ServiceSection $b): int => $a->start_time <=> $b->start_time);
            $first = $selected[0];
            $last = $selected[count($selected) - 1];
            $reference = $sermon->metadata?->raw['sermon_reference'] ?? null;
            $readings = array_values(array_filter($sections, static fn (ServiceSection $section): bool => $section->section_type === ServiceSectionType::BibleReading && $section->start_time < $first->start_time));
            $membership = $this->scriptureReferences->sermonReadingMembership(
                is_string($reference) ? $reference : null,
                array_map(static fn (ServiceSection $reading): ?string => $reading->metadata?->readingReference, $readings),
            );
            if ($membership['selected'] !== null) {
                $selected[] = $readings[$membership['selected']];
            } elseif ($membership['review']) {
                // A reading sharing verses without holding the sermon's passage is a sermon reading
                // past it, or one part of a multipart reference: plausible, so asked, never dropped.
                $risks[] = ['kind' => 'sermon_reading_membership_unresolved', 'detail' => 'Choose the sermon reading: references are missing or multiple readings are plausible.'];
            }
            $following = array_values(array_filter($sections, static fn (ServiceSection $section): bool => $section->start_time >= $last->end_time && ! in_array($section, $selected, true)));
            $beforeSong = [];
            foreach ($following as $section) {
                if ($section->section_type === ServiceSectionType::Song) {
                    break;
                }
                $beforeSong[] = $section;
            }
            $prayers = array_values(array_filter($beforeSong, static fn (ServiceSection $section): bool => $section->section_type === ServiceSectionType::Prayer));
            if (count($prayers) === 1 && ($beforeSong[0] ?? null) === $prayers[0]) {
                $selected[] = $prayers[0];
            } elseif ($prayers !== []) {
                $risks[] = ['kind' => 'sermon_prayer_membership_unresolved', 'detail' => 'Choose the concluding prayer: intervening sections or multiple prayers make membership uncertain.'];
            }
            try {
                foreach ($this->uncoveredSpeech($log, $sections, $selected, $sermon->id) as $cue) {
                    $risks[] = ['kind' => 'sermon_uncovered_speech', 'detail' => sprintf('Speech outside identified sections at %.3f–%.3fs: %s', $cue['start'], $cue['end'], $cue['text'])];
                }
            } catch (OutputEdgeTimingsMissing $exception) {
                $pendingEdgeEvidence = $exception->getMessage();
            }
        }

        $review = $log->processing_metadata?->raw['sermon_composition_review'] ?? null;
        if (is_array($review) && ($review['input_identity'] ?? null) === $identity && is_array($review['selected_section_ids'] ?? null)) {
            $byId = collect($sections)->keyBy('id');
            $selected = [];
            foreach ($review['selected_section_ids'] as $id) {
                $section = is_int($id) ? $byId->get($id) : null;
                if (! $section instanceof ServiceSection) {
                    throw new InvalidArgumentException('Sermon composition names a section outside its source run');
                }
                $selected[] = $section;
            }
            if ($sermon !== null && ! in_array($sermon, $selected, true)) {
                throw new InvalidArgumentException('Sermon composition must include the identified sermon');
            }
            $risks = [];
        }
        usort($selected, static fn (ServiceSection $a, ServiceSection $b): int => $a->start_time <=> $b->start_time);
        $composition = [
            'edge_word_timings_pending' => $pendingEdgeEvidence,
            'input_identity' => $identity,
            'selected_section_ids' => array_map(static fn (ServiceSection $section): int => $section->id, $selected),
            'sermon_section_id' => $sermon?->id,
            'bible_section_id' => collect($selected)->first(fn (ServiceSection $section): bool => $section->section_type === ServiceSectionType::BibleReading)?->id,
            'trailing_section_ids' => array_values(array_map(fn (ServiceSection $section): int => $section->id, array_filter($selected, fn (ServiceSection $section): bool => $section->section_type === ServiceSectionType::Prayer))),
            'requires_review' => $risks !== [],
            'risks' => $risks,
            'method' => 'identified_sections',
            'decision' => $risks === [] ? 'section_exact' : 'review',
        ];
        $log->writeProcessingMetadata(static function (array $metadata) use ($composition): array {
            $metadata['sermon_composition'] = $composition;

            return $metadata;
        });
        if ($sermon !== null) {
            $metadata = $sermon->metadata?->toArray() ?? [];
            $metadata['sermon_boundary'] = $composition;
            $flags = array_values(array_diff($sermon->metadata->reviewFlags ?? [], [SermonAutoExtractionPolicy::COMPOSITION_REVIEW_FLAG]));
            if ($risks !== []) {
                $flags[] = SermonAutoExtractionPolicy::COMPOSITION_REVIEW_FLAG;
                $sermon->needs_manual_review = true;
            } elseif ($flags === [] && ($sermon->metadata->reviewFlags ?? []) !== []) {
                $sermon->needs_manual_review = false;
            }
            $metadata['review_flags'] = $flags;
            $sermon->metadata = ServiceSectionMetadata::fromArray($metadata);
            $sermon->save();
        }

        return $composition;
    }

    /** @param list<int> $sectionIds */
    public function reviewComposition(MediaProcessingLog $log, array $sectionIds, string $inputIdentity, int $userId): void
    {
        $current = $this->compose($log);
        if ($current['input_identity'] !== $inputIdentity) {
            throw new InvalidArgumentException('Sections or coverage evidence changed; reload before reviewing composition.');
        }
        if ($sectionIds === [] || count($sectionIds) !== count(array_unique($sectionIds))) {
            throw new InvalidArgumentException('Choose each selected section once.');
        }
        $sections = $log->serviceSections()->whereIn('id', $sectionIds)->get();
        if ($sections->count() !== count($sectionIds)) {
            throw new InvalidArgumentException('Composition contains a section outside this run.');
        }
        foreach ($sections as $section) {
            if (! in_array($section->section_type, [ServiceSectionType::Sermon, ServiceSectionType::BibleReading, ServiceSectionType::Prayer], true)
                && ! $this->continues($section, (int) $current['sermon_section_id'])) {
                throw new InvalidArgumentException('Select only the sermon reading, sermon parts and concluding prayer.');
            }
        }
        if (! in_array($current['sermon_section_id'], $sectionIds, true)) {
            throw new InvalidArgumentException('Composition must include the identified sermon.');
        }
        $previousEnd = 0.0;
        foreach ($sections->sortBy('start_time') as $section) {
            $start = (float) $section->start_time;
            $end = (float) $section->end_time;
            if (! is_finite($start) || ! is_finite($end) || $start < $previousEnd || $end <= $start
                || ($log->duration !== null && $end > $log->duration + 0.001)) {
                throw new InvalidArgumentException('Invalid selected section bounds: unordered, overlapping or outside source');
            }
            $previousEnd = $end;
        }
        $sermonParts = $sections->filter(fn (ServiceSection $section): bool => $section->id === $current['sermon_section_id']
            || $this->continues($section, (int) $current['sermon_section_id']));
        $sermonStart = (float) $sermonParts->min('start_time');
        $sermonEnd = (float) $sermonParts->max('end_time');
        $songStart = $log->serviceSections()->where('section_type', ServiceSectionType::Song)
            ->where('start_time', '>=', $sermonEnd)->min('start_time');
        foreach ($sections as $section) {
            if (($section->section_type === ServiceSectionType::BibleReading && $section->end_time > $sermonStart)
                || ($section->section_type === ServiceSectionType::Prayer
                    && ($section->start_time < $sermonEnd || ($songStart !== null && $section->end_time > $songStart)))) {
                throw new InvalidArgumentException('Select the reading before the sermon and the concluding prayer before the post-sermon song.');
            }
        }
        $log->writeProcessingMetadata(static function (array $metadata) use ($sectionIds, $inputIdentity, $userId): array {
            $metadata['sermon_composition_review'] = [
                'selected_section_ids' => $sectionIds,
                'input_identity' => $inputIdentity,
                'coverage_resolved' => true,
                'reviewed_by_user_id' => $userId,
                'reviewed_at' => now()->toIso8601String(),
            ];

            return $metadata;
        });
        $this->compose($log);
    }

    /**
     * @param  array{section_id: int, start_time: float, end_time: float}|null  $heldSpanAuthority
     * @return array{mode: 'single_span'|'concat_spans', source: 'service_sections', segments: list<array{start_time: float, end_time: float}>, metadata: array<string, mixed>}
     */
    public function resolve(MediaProcessingLog $processingLog, ?array $heldSpanAuthority = null): array
    {
        $sections = $processingLog->serviceSections()->orderBy('start_time')->orderBy('id')->get()->values()->all();
        $composition = $processingLog->processing_metadata?->raw['sermon_composition'] ?? null;
        // Bootstrap old runs deterministically; detection and review normally compose first.
        if (! is_array($composition) || ($composition['input_identity'] ?? null) !== $this->inputIdentity($processingLog, $sections)) {
            $composition = $this->compose($processingLog);
            $sections = $processingLog->serviceSections()->orderBy('start_time')->orderBy('id')->get()->values()->all();
        }
        $byId = collect($sections)->keyBy('id');
        $spans = [];
        $held = [];
        $requiresReview = ($composition['requires_review'] ?? true) === true;
        $authority = $heldSpanAuthority ?? $processingLog->authorisedHeldSermonSpan();
        $previousEnd = 0.0;
        $seen = [];
        foreach ($composition['selected_section_ids'] ?? [] as $id) {
            $section = is_int($id) ? $byId->get($id) : null;
            if (! $section instanceof ServiceSection || in_array($id, $seen, true)) {
                throw new InvalidArgumentException('Sermon composition repeats a section or names one outside its source run');
            }
            $start = (float) $section->start_time;
            $end = (float) $section->end_time;
            if (! is_finite($start) || ! is_finite($end) || $start < $previousEnd || $end <= $start
                || ($processingLog->duration !== null && $end > $processingLog->duration + 0.001)) {
                throw new InvalidArgumentException('Invalid selected section bounds: unordered, overlapping or outside source');
            }
            $seen[] = $id;
            $previousEnd = $end;
            $flags = $section->metadata->reviewFlags ?? [];
            $isHeld = HoldSectionForContentReview::isHeld($flags);
            $authorised = $isHeld && $authority !== null && $section->id === $authority['section_id']
                && abs($start - $authority['start_time']) < 0.001 && abs($end - $authority['end_time']) < 0.001
                && SermonAutoExtractionPolicy::reviewStatePermitsHeldSpanRepair($flags);
            if ($isHeld && ! $authorised) {
                $held[] = $id;
                $requiresReview = true;
            } elseif ((! $authorised && ! SermonAutoExtractionPolicy::reviewStatePermitsAutoExtraction((bool) $section->needs_manual_review, $flags))) {
                $requiresReview = true;
            }
            $spans[] = ['start_time' => $start, 'end_time' => $end];
        }

        try {
            $cuePlan = app(CueSafeExtractionPlan::class)->forSpans($processingLog, $spans);
        } catch (OutputEdgeTimingsMissing $exception) {
            return ['mode' => 'single_span', 'source' => 'service_sections', 'segments' => [],
                'metadata' => [...$composition, 'requires_review' => true, 'reason' => 'edge_word_timings_missing',
                    'edge_word_timings_error' => $exception->getMessage(), 'cue_edge_widening' => []]];
        }
        $spans = $cuePlan['segments'];
        foreach ($spans as $span) {
            if ($span['end_time'] <= $span['start_time'] || $span['start_time'] < 0 || ($processingLog->duration !== null && $span['end_time'] > $processingLog->duration + 0.001)) {
                throw new InvalidArgumentException('Widened cut bounds are outside source');
            }
        }
        // Selected sections answer for their own holds above; a widened edge must not carry
        // another section's held content in, whatever authority the selected sections have.
        $crossed = app(CueSafeExtractionPlan::class)->heldSectionsCrossed($processingLog, $spans, $seen);
        $requiresReview = $requiresReview || $crossed !== [];

        return [
            'mode' => count($spans) > 1 ? 'concat_spans' : 'single_span',
            'source' => 'service_sections',
            'segments' => $spans,
            'metadata' => [
                ...$composition,
                'cue_edge_widening' => $cuePlan['cue_edge_widening'],
                'strategy' => 'identified_sections',
                'requires_review' => $requiresReview,
                'reason' => match (true) {
                    $held !== [] => 'sermon_section_content_held',
                    $crossed !== [] => 'sermon_span_crosses_held_section',
                    $requiresReview => 'sermon_composition_review',
                    default => null,
                },
                'held_sermon_section_ids' => $held,
                'crossed_held_section_ids' => $crossed,
                'sermon_boundary' => $composition,
                'continuation_section_ids' => array_values(array_filter($seen, fn (int $id): bool => $id !== $composition['sermon_section_id'] && $byId->get($id) instanceof ServiceSection && $this->continues($byId->get($id), (int) $composition['sermon_section_id']))),
                'held_span_authorised_section_id' => $authority['section_id'] ?? null,
            ],
        ];
    }

    public function hasAutoExtractableSermonSection(MediaProcessingLog $processingLog): bool
    {
        $plan = $this->resolve($processingLog);

        return $plan['segments'] !== [] && ($plan['metadata']['requires_review'] ?? true) === false;
    }

    /**
     * A sermon part is either recorded by a marker or named by the detector's own notes on an
     * `other` section. Reading the notes here means no separate screening pass, and no
     * re-projection that drops the marker, can leave a part out of the cut.
     */
    private function continues(ServiceSection $section, int $sermonId): bool
    {
        if ($section->metadata?->sermonContinuation?->continues($sermonId) === true) {
            return true;
        }

        return $this->notedContinuation($section) !== null;
    }

    /** @return array{evidence: string, source: 'detector_notes'}|null */
    private function notedContinuation(ServiceSection $section): ?array
    {
        if ($section->section_type !== ServiceSectionType::Other) {
            return null;
        }

        $evidence = $this->continuations->evidence($section);

        return $evidence === null ? null : ['evidence' => $evidence, 'source' => 'detector_notes'];
    }

    /** @param array<int, ServiceSection> $sections */
    private function inputIdentity(MediaProcessingLog $log, array $sections): string
    {
        return hash('sha256', (string) json_encode([
            'sections' => array_map(fn (ServiceSection $section): array => [
                $section->id, $section->section_type->value, (float) $section->start_time, (float) $section->end_time,
                $section->metadata?->readingReference, $section->metadata?->raw['sermon_reference'] ?? null,
                $section->metadata?->sermonContinuation?->toArray() ?? $this->notedContinuation($section),
            ], $sections),
            'duration' => $log->duration,
            'transcript' => $this->evidenceHash($log->serviceTranscriptPath()),
            'audio_timeline' => $this->evidenceHash($log->audio_timeline_path),
        ]));
    }

    private function evidenceHash(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }
        $disk = Storage::disk(ServiceArtifactDisk::for($path));
        if (! $disk->exists($path)) {
            return 'missing';
        }
        $raw = $disk->get($path);

        return is_string($raw) ? hash('sha256', $raw) : 'missing';
    }

    /**
     * Use timed words corroborated as speech only between identified parts of the same sermon.
     * Identified songs and other sections already cover their intentionally excluded content.
     *
     * @param  array<int, ServiceSection>  $sections
     * @param  list<ServiceSection>  $selected
     * @return list<array{start: float, end: float, text: string}>
     */
    private function uncoveredSpeech(MediaProcessingLog $log, array $sections, array $selected, int $sermonId): array
    {
        usort($selected, static fn (ServiceSection $a, ServiceSection $b): int => $a->start_time <=> $b->start_time);
        $gaps = [];
        foreach ($selected as $index => $section) {
            $next = $selected[$index + 1] ?? null;
            if ($next !== null && $section->end_time < $next->start_time
                && ($section->id === $sermonId || $this->continues($section, $sermonId))
                && ($next->id === $sermonId || $this->continues($next, $sermonId))) {
                $pair = app(CueSafeExtractionPlan::class)->forSpans($log, [
                    ['start_time' => (float) $section->start_time, 'end_time' => (float) $section->end_time],
                    ['start_time' => (float) $next->start_time, 'end_time' => (float) $next->end_time],
                ])['segments'];
                if (count($pair) === 2) {
                    $gaps[] = [$pair[0]['end_time'], $pair[1]['start_time']];
                }
            }
        }
        if ($gaps === []) {
            return [];
        }
        $before = $gaps[0][0];
        $after = $gaps[count($gaps) - 1][1];
        $path = $log->serviceTranscriptPath();
        $timelinePath = $log->audio_timeline_path;
        if ($path === null && $timelinePath === null) {
            return [];
        }
        if ($path === null || $timelinePath === null) {
            return [['start' => $before, 'end' => $after, 'text' => 'Required coverage evidence is missing.']];
        }
        $transcriptDisk = Storage::disk(ServiceArtifactDisk::for($path));
        $timelineDisk = Storage::disk(ServiceArtifactDisk::for($timelinePath));
        if (! $transcriptDisk->exists($path) || ! $timelineDisk->exists($timelinePath)) {
            return [['start' => $before, 'end' => $after, 'text' => 'Required coverage evidence is missing.']];
        }
        $raw = $transcriptDisk->get($path);
        $audio = $timelineDisk->get($timelinePath);
        if (! is_string($raw) || ! is_string($audio)) {
            return [['start' => $before, 'end' => $after, 'text' => 'Required coverage evidence is missing.']];
        }
        $transcript = ChurchServiceTranscript::fromArray(json_decode($raw, true));
        $timeline = AudioTimeline::fromJson($audio);
        $uncovered = [];
        foreach ($transcript->cues as $cue) {
            $pieces = [];
            foreach ($gaps as [$from, $to]) {
                $start = max($from, $cue['start']);
                $end = min($to, $cue['end']);
                if ($end > $start) {
                    $pieces[] = [$start, $end];
                }
            }
            foreach ($sections as $section) {
                $remaining = [];
                foreach ($pieces as [$from, $to]) {
                    if ($section->end_time <= $from || $section->start_time >= $to) {
                        $remaining[] = [$from, $to];

                        continue;
                    }
                    if ($section->start_time > $from) {
                        $remaining[] = [$from, (float) $section->start_time];
                    }
                    if ($section->end_time < $to) {
                        $remaining[] = [(float) $section->end_time, $to];
                    }
                }
                $pieces = $remaining;
            }
            foreach ($pieces as [$from, $to]) {
                if ($timeline->speechShare($from, $to) > 0) {
                    $uncovered[] = ['start' => $from, 'end' => $to, 'text' => $cue['text']];
                }
            }
        }

        return $uncovered;
    }
}
