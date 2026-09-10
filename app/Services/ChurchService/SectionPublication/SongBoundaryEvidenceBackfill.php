<?php

declare(strict_types=1);

namespace App\Services\ChurchService\SectionPublication;

use App\Data\HistoricStagingContext;
use App\Data\ServiceSectionMetadata;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Services\HistoricMedia\HistoricStagingGuard;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Bank song boundary evidence for clips that were published before the evidence
 * was ever recorded.
 *
 * P8-Q10's residue. Boundary evidence banking begins at `version: 1`,
 * `recorded_at` 2026-09-07; every song section published before that pass holds
 * none, so nothing can say whether its start and end are supported.
 *
 * **A run's artifact keys only resolve while its staging context is active.**
 * The keys recorded on a historic run are relative to the staging disk root, but
 * the files live under `historic-batches/<planHash>/`, and
 * {@see HistoricStagingGuard::activate()} binds that
 * prefix onto the disk root only for the duration of a historic job. Assessing
 * without reactivating reports every input `missing` and every section
 * `song_boundary_evidence_unavailable` — a corpus-wide false negative that reads
 * exactly like reaped media. So each run is assessed inside its own recorded
 * context.
 *
 * This mirrors what {@see SongPublicationHandler::requiresApproval()} would have
 * written at publish time, holds included: a re-derived doubt about a published
 * clip is a real doubt, and leaving it unrecorded would be the fail-open
 * reading. Taking a newly held section out of view is deliberately *not* done
 * here — `service:demote-held-publications` owns that, and reads
 * `needs_manual_review`, which is what this sets.
 *
 * Deletion trigger: delete once every published and pending song section carries
 * banked boundary evidence and the Phase 8 release census has run.
 */
class SongBoundaryEvidenceBackfill
{
    public const DispositionAssessed = 'assessed';

    public const DispositionUnavailable = 'evidence_unavailable';

    public const DispositionFailed = 'failed';

    public function __construct(
        private readonly SongPublicationReviewPolicy $reviewPolicy,
        private readonly HistoricStagingContextRegistry $stagingContexts,
    ) {}

    /**
     * Assess every section, grouped so each run's staging context is entered once.
     *
     * @param  Collection<int, ServiceSection>  $sections
     * @return list<array{
     *     section:int,
     *     run:int|null,
     *     disposition:string,
     *     reactivated:bool,
     *     decision:string|null,
     *     risks:list<string>,
     *     reasons:list<string>,
     *     holds:bool,
     *     detail:string|null
     * }>
     */
    public function inspect(Collection $sections): array
    {
        $entries = [];

        foreach ($sections->groupBy('media_processing_log_id') as $runId => $runSections) {
            $context = $this->stagingContextFor(MediaProcessingLog::find($runId));

            $assess = function () use ($runSections, $context, &$entries): void {
                foreach ($runSections as $section) {
                    $entries[] = $this->assessSection($section, $context !== null);
                }
            };

            if ($context === null) {
                $assess();

                continue;
            }

            $this->stagingContexts->within($context, $assess);
        }

        usort($entries, static fn (array $a, array $b): int => $a['section'] <=> $b['section']);

        return $entries;
    }

    /**
     * Write the banked evidence for one already-assessed section.
     *
     * Re-derives the assessment rather than trusting the inspect entry, because
     * the write must happen under the same activation the read did.
     */
    public function apply(ServiceSection $section): bool
    {
        $context = $this->stagingContextFor(
            MediaProcessingLog::find($section->media_processing_log_id),
        );

        $write = fn (): bool => $this->writeSection($section);

        return $context === null ? $write() : $this->stagingContexts->within($context, $write);
    }

    /**
     * @return array{
     *     section:int,
     *     run:int|null,
     *     disposition:string,
     *     reactivated:bool,
     *     decision:string|null,
     *     risks:list<string>,
     *     reasons:list<string>,
     *     holds:bool,
     *     detail:string|null
     * }
     */
    private function assessSection(ServiceSection $section, bool $reactivated): array
    {
        $base = [
            'section' => $section->id,
            'run' => $section->media_processing_log_id,
            'reactivated' => $reactivated,
        ];

        try {
            $assessment = $this->reviewPolicy->assess($section);
        } catch (Throwable $e) {
            return $base + [
                'disposition' => self::DispositionFailed,
                'decision' => null,
                'risks' => [],
                'reasons' => [],
                'holds' => false,
                'detail' => $e->getMessage(),
            ];
        }

        $evidence = $assessment['boundary_evidence'];
        $risks = array_column($evidence['risks'] ?? [], 'kind');
        $reasons = array_column($assessment['reasons'], 'kind');

        /*
         * An unreadable input is reported, never banked. Banking it would record
         * "we looked and found nothing" as though it were evidence, and a later
         * pass could not tell that apart from a clip that was genuinely assessed.
         */
        $unavailable = in_array('song_boundary_evidence_unavailable', $risks, true)
            || in_array('song_boundary_evidence_unreadable', $risks, true);

        return $base + [
            'disposition' => $unavailable ? self::DispositionUnavailable : self::DispositionAssessed,
            'decision' => $evidence['decision'] ?? null,
            'risks' => $risks,
            'reasons' => $reasons,
            'holds' => $reasons !== [],
            'detail' => null,
        ];
    }

    private function writeSection(ServiceSection $section): bool
    {
        $assessment = $this->reviewPolicy->assess($section);
        $risks = array_column($assessment['boundary_evidence']['risks'] ?? [], 'kind');

        if (in_array('song_boundary_evidence_unavailable', $risks, true)
            || in_array('song_boundary_evidence_unreadable', $risks, true)) {
            return false;
        }

        $metadata = $section->metadata?->toArray() ?? [];
        $metadata[SongPublicationBoundaryEvidenceService::METADATA_KEY] = $assessment['boundary_evidence'];
        $reasons = $assessment['reasons'];

        if ($reasons !== []) {
            $metadata['song_publication_review'] = [
                'reasons' => $reasons,
                'decided_at' => now()->toISOString(),
            ];
        } else {
            unset($metadata['song_publication_review']);
        }

        $section->metadata = ServiceSectionMetadata::fromArray($metadata);

        if ($reasons !== []) {
            $section->needs_manual_review = true;
        }

        $section->save();

        return true;
    }

    private function stagingContextFor(?MediaProcessingLog $log): ?HistoricStagingContext
    {
        if (! $log instanceof MediaProcessingLog) {
            return null;
        }

        try {
            return $log->historicStagingContext();
        } catch (Throwable) {
            /*
             * A malformed recorded context is not a reason to assess without
             * one: that would silently produce the false-unavailable reading
             * this class exists to avoid. Assess unreactivated and let the
             * disposition say so.
             */
            return null;
        }
    }
}
