<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Actions\HoldSectionForContentReview;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Sermon;
use App\Models\SongVideo;

/**
 * What a corpus re-run can change about one run, captured so the change can be read afterwards.
 *
 * The historic corpus re-run (plan §4.0) re-detects every eligible run against one frozen
 * commit. Review then reads "what changed and why" from a before/after comparison instead of
 * re-examining 437 runs, so the capture holds exactly what re-detection, re-matching and
 * re-extraction write: sections with their types, spans and bindings, review flags, content
 * holds with the check that found them, talk types, the lyric identity verdict, extracted
 * media, the sermon's span and extraction plan, and the transcript the run read.
 *
 * Read-only, and a projection rather than a copy: section metadata runs to kilobytes of
 * evidence per row, most of which a re-run rewrites without changing its meaning. Section ids
 * are kept for the reader but never used to pair sections, because sync recreates rows and
 * renumbers them on insert ({@see HistoricRerunDiff}).
 *
 * Delete once the corpus re-run's batches are accepted, alongside its other instruments.
 */
final class HistoricRerunState
{
    /** Bumped whenever the captured shape changes, so a diff refuses to compare across shapes. */
    public const VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public function capture(MediaProcessingLog $run): array
    {
        $metadata = $run->processing_metadata?->toArray() ?? [];

        $sections = $run->serviceSections()
            ->with(['churchServiceItem', 'songVideos', 'publishedSermon'])
            ->orderBy('start_time')
            ->orderBy('section_order')
            ->get();

        return [
            'run_id' => $run->id,
            'church_service_id' => $run->church_service_id,
            'status' => $run->status->value,
            'current_step' => $run->current_step,
            'manual_review_reason' => data_get($metadata, 'manual_review.status') === 'required' ? data_get($metadata, 'manual_review.reason_code') : null,
            'superseded' => $run->superseded_at !== null,
            'transcript_sha256' => $this->transcriptSha256($run),
            'sermon_absence' => $run->assertedSermonAbsence() !== null,
            ...$this->sermonPlan($run, $metadata),
            'sermon' => $this->sermon($run->sermon),
            'sections' => $sections->map(fn (ServiceSection $section): array => $this->section($section))->values()->all(),
        ];
    }

    /**
     * The sermon span and the plan it was cut on, or, after a detection round that deferred its
     * media (plan §4.0), the plan the round recorded for extraction to cut: the run's own fields
     * still describe the media that exists, which the round did not replace.
     *
     * @param  array<string, mixed>  $metadata
     * @return array{media_deferred: bool, sermon_span: array{start: float, end: float}|null, extraction_plan: array<string, mixed>|null}
     */
    private function sermonPlan(MediaProcessingLog $run, array $metadata): array
    {
        if (! $run->hasDeferredCorpusRerunMedia()) {
            return [
                'media_deferred' => false,
                'sermon_span' => $this->span($run->sermon_start_time, $run->sermon_end_time),
                'extraction_plan' => $this->extractionPlan($metadata['sermon_extraction_plan'] ?? null),
            ];
        }

        $stamps = $run->corpusRerunStamps();
        $plan = $stamps[count($stamps) - 1]['deferred_extraction_plan'] ?? null;
        $segments = array_values(array_filter(is_array($plan) ? (array) ($plan['segments'] ?? []) : [], 'is_array'));

        return [
            'media_deferred' => true,
            'sermon_span' => $segments === []
                ? null
                : $this->span($segments[0]['start_time'] ?? null, $segments[count($segments) - 1]['end_time'] ?? null),
            'extraction_plan' => $this->extractionPlan($plan),
        ];
    }

    /**
     * The hash of the transcript structure detection reads, or a marker when it cannot be read.
     * An unreadable transcript is recorded, never skipped: it is the difference between "the
     * re-run left the text alone" and "nobody could look".
     */
    private function transcriptSha256(MediaProcessingLog $run): string
    {
        if ($run->serviceTranscriptPath() === null) {
            return 'none';
        }

        $contents = $run->storedServiceTranscriptContents();

        return $contents === null ? 'unreadable' : hash('sha256', $contents);
    }

    /**
     * @return array{start: float, end: float}|null
     */
    private function span(mixed $start, mixed $end): ?array
    {
        if (! is_numeric($start) || ! is_numeric($end)) {
            return null;
        }

        return ['start' => round((float) $start, 2), 'end' => round((float) $end, 2)];
    }

    /**
     * The plan's decision and cut spans. Section ids inside `sermon_boundary` are dropped: they
     * change whenever sync recreates rows, which would make every re-run look like a new plan.
     *
     * @return array<string, mixed>|null
     */
    private function extractionPlan(mixed $plan): ?array
    {
        if (! is_array($plan)) {
            return null;
        }

        $segments = [];

        foreach ((array) ($plan['segments'] ?? []) as $segment) {
            if (is_array($segment)) {
                $segments[] = $this->span($segment['start_time'] ?? null, $segment['end_time'] ?? null);
            }
        }

        return [
            'mode' => $plan['mode'] ?? null,
            'strategy' => $plan['strategy'] ?? null,
            'reason' => $plan['reason'] ?? null,
            'segments' => $segments,
            'boundary_decision' => data_get($plan, 'sermon_boundary.decision'),
            'boundary_requires_review' => data_get($plan, 'sermon_boundary.requires_review'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sermon(?Sermon $sermon): ?array
    {
        if (! $sermon instanceof Sermon) {
            return null;
        }

        return [
            'id' => $sermon->id,
            'title' => $sermon->title,
            'reference' => $sermon->reference,
            'scripture_passage_id' => $sermon->scripture_passage_id,
            'segment' => $this->span($sermon->segment_start_time, $sermon->segment_end_time),
            'duration' => is_numeric($sermon->duration) ? round((float) $sermon->duration, 2) : null,
            'content_type' => $sermon->content_type->value,
            'publication_state' => $sermon->publication_state->value,
            'video_quality_status' => $sermon->video_quality_status?->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function section(ServiceSection $section): array
    {
        $metadata = $section->metadata?->toArray() ?? [];
        $reviewFlags = array_values(array_filter((array) ($metadata['review_flags'] ?? []), 'is_string'));
        sort($reviewFlags);

        return [
            'id' => $section->id,
            'order' => $section->section_order,
            'type' => $section->section_type->value,
            'title' => $section->title,
            'start' => round((float) $section->start_time, 2),
            'end' => round((float) $section->end_time, 2),
            'item_id' => $section->church_service_item_id,
            'song_id' => $section->resolvedSongId(),
            'song_match_type' => $section->song_match_type?->value,
            'lyric_identity' => data_get($metadata, 'lyric_identity_check.verdict'),
            'proposed_talk_type' => $section->metadata?->talkType?->proposed?->value,
            'confirmed_talk_type' => $section->publicationTalkType()?->value,
            'status' => $section->status->value,
            'needs_manual_review' => $section->needs_manual_review,
            'publication_status' => $section->publication_status->value,
            'published_sermon_state' => $section->publishedSermon?->publication_state->value,
            'review_flags' => $reviewFlags,
            'song_review' => $this->songReview($metadata),
            'holds' => $this->holds($metadata),
            'media' => [
                'signature' => $section->extracted_video_path === null && $section->extracted_audio_path === null
                    ? null
                    : $section->mediaSignature(),
                'extracted_at' => $section->extracted_at?->toIso8601String(),
            ],
            'song_videos' => $section->songVideos
                ->sortBy('id')
                ->map(static fn (SongVideo $video): array => [
                    'id' => $video->id,
                    'song_id' => $video->song_id,
                    'publication_state' => $video->publication_state->value,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * The doubts a song's publication review names, by kind. A detection round decides them
     * without a clip ({@see \App\Jobs\RecordDeferredCorpusRerunMedia}).
     *
     * @param  array<string, mixed>  $metadata
     * @return list<string>
     */
    private function songReview(array $metadata): array
    {
        $kinds = array_values(array_filter(array_column((array) data_get($metadata, 'song_publication_review.reasons', []), 'kind'), 'is_string'));
        sort($kinds);

        return $kinds;
    }

    /**
     * Every hold record, live or not, with what found it. A live record the re-run drops is the
     * one change the diff must never let pass unnoticed.
     *
     * @param  array<string, mixed>  $metadata
     * @return list<array{found_by: string|null, reason: string, start: float|null, end: float|null, live: bool}>
     */
    private function holds(array $metadata): array
    {
        $holds = [];

        foreach (HoldSectionForContentReview::holdsIn($metadata) as $record) {
            $holds[] = [
                'found_by' => is_string($record['found_by'] ?? null) ? $record['found_by'] : null,
                'reason' => (string) ($record['reason'] ?? ''),
                'start' => is_numeric($record['start_time'] ?? null) ? round((float) $record['start_time'], 2) : null,
                'end' => is_numeric($record['end_time'] ?? null) ? round((float) $record['end_time'], 2) : null,
                'live' => HoldSectionForContentReview::isLive($record),
            ];
        }

        return $holds;
    }
}
