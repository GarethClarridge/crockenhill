<?php

declare(strict_types=1);

namespace App\Queries;

use App\Actions\HoldSectionForContentReview;
use App\Enums\ServiceSectionType;
use App\Models\ServiceSection;
use App\Services\ChurchService\ReviewInventory\PendingApplication;
use App\Services\ChurchService\ReviewInventory\ReviewReasonRouter;
use App\Support\SongPublicationReviewHold;
use Illuminate\Support\LazyCollection;

/**
 * One record per actionable review reason (video plan §4.1d RA1), over exactly the sections the
 * dashboard counts: the same base predicate and the same reviewReasons(), read in bounded id
 * chunks rather than the dashboard's sorted, blob-heavy load. A manual-review reason is split
 * into its review flags (a content hold into each recorded hold's check) and a song-boundary
 * reason into its kinds, because each is decided separately.
 */
final class ServiceReviewInventoryQuery
{
    /** The candidate predicate's legacy song clause, which reviewReasons() gives no reason of its own. */
    public const SONG_WITHOUT_CATALOGUE_ITEM = 'song_without_catalogue_item';

    /** A manual-review flag held by the song's publication review rather than by a review flag. */
    public const SONG_PUBLICATION_HOLD = 'song_publication_hold';

    private const CHUNK = 200;

    public function __construct(
        private readonly ServiceReviewDashboardQuery $dashboard,
        private readonly ReviewReasonRouter $router,
    ) {}

    /**
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function records(PendingApplication $pending, bool $includePublicationApprovals = true): LazyCollection
    {
        return $this->dashboard->reviewCandidateSectionsBaseQuery($includePublicationApprovals)
            ->with([
                'processingLog:id,processing_id,status,church_service_id,file_hash,superseded_at',
                'processingLog.churchService:id,date,service',
            ])
            ->lazyById(self::CHUNK)
            ->flatMap(fn (ServiceSection $section): array => $this->recordsFor($section, $pending));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recordsFor(ServiceSection $section, PendingApplication $pending): array
    {
        $records = [];
        foreach ($this->reasonsOf($section) as [$reason, $detail]) {
            $route = $this->router->route($section, $reason, $detail, $pending);
            $records[] = [
                ...$this->identity($section, $pending),
                'reason' => $reason,
                'detail' => $detail,
                'disposition' => $route['disposition']->value,
                'clears_without_human_work' => $route['disposition']->clearsWithoutHumanWork(),
                'stage' => $route['stage'],
                'next_operation' => $route['next_operation'],
                'ruling' => $route['ruling'],
                'missing_input' => $route['missing_input'],
                'fix' => $route['fix'],
            ];
        }

        return $records;
    }

    /**
     * @return list<array{0: string, 1: string|null}>
     */
    private function reasonsOf(ServiceSection $section): array
    {
        $metadata = $section->metadata?->toArray() ?? [];
        $reasons = [];
        foreach ($this->dashboard->reviewReasons($section) as $reason) {
            $key = $reason['key'];
            if ($key === 'needs_manual_review') {
                $flags = array_values(array_filter($metadata['review_flags'] ?? [], is_string(...)));
                if ($flags === []) {
                    // The flag's other source (SectionReviewFlagRecalculator): a song's publication review holds it.
                    $flags = [SongPublicationReviewHold::keepsHold($section, $metadata) ? self::SONG_PUBLICATION_HOLD : null];
                }
                foreach ($flags as $flag) {
                    if ($flag === HoldSectionForContentReview::FLAG) {
                        $holds = array_filter($metadata[HoldSectionForContentReview::METADATA_KEY] ?? [], is_array(...));
                        foreach ($holds === [] ? [[]] : $holds as $hold) {
                            $reasons[] = [$key, 'content_hold:'.(is_string($hold['found_by'] ?? null) ? $hold['found_by'] : '')];
                        }

                        continue;
                    }
                    $reasons[] = [$key, $flag];
                }
            } elseif ($key === 'song_publication_review') {
                foreach ($metadata['song_publication_review']['reasons'] ?? [] as $songReason) {
                    if (is_array($songReason) && is_string($songReason['detail'] ?? null) && trim($songReason['detail']) !== '') {
                        $reasons[] = [$key, is_string($songReason['kind'] ?? null) ? $songReason['kind'] : null];
                    }
                }
            } else {
                $reasons[] = [$key, null];
            }
        }

        if ($reasons === []) {
            // The base query admits it through its legacy song clause, or through a predicate
            // reviewReasons() does not mirror; either way it is in the dashboard's count.
            $reasons[] = [$section->section_type === ServiceSectionType::Song ? self::SONG_WITHOUT_CATALOGUE_ITEM : 'unexplained_candidate', null];
        }

        return array_values(array_unique($reasons, SORT_REGULAR));
    }

    /**
     * @return array<string, mixed>
     */
    private function identity(ServiceSection $section, PendingApplication $pending): array
    {
        $run = $section->processingLog;
        $metadata = $section->metadata?->toArray() ?? [];
        $candidate = $metadata['publication_candidate_extraction'] ?? null;
        $service = $run->churchService;

        return [
            'section_id' => $section->id,
            'run_id' => $section->media_processing_log_id,
            'processing_id' => $run->processing_id,
            'run_status' => $run->status->value,
            'replayed' => $pending->replayed((int) $section->media_processing_log_id),
            'church_service_id' => $service?->id,
            'service_date' => $service?->date?->toDateString(),
            'service' => $service?->service->value ?? null,
            'section_type' => $section->section_type->value,
            'section_order' => $section->section_order,
            'title' => $section->title,
            'start_time' => (float) $section->start_time,
            'end_time' => (float) $section->end_time,
            'publication_status' => $section->publication_status->value,
            'held' => HoldSectionForContentReview::isHeld($metadata['review_flags'] ?? []),
            'source_file_hash' => $run->file_hash,
            'output_generation' => [
                'video' => $section->extracted_video_path,
                'audio' => $section->extracted_audio_path,
                'candidate_signature' => is_array($candidate) ? ($candidate['media_processing'] ?? null) : null,
            ],
        ];
    }
}
