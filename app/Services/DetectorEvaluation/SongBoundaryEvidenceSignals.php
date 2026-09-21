<?php

declare(strict_types=1);

namespace App\Services\DetectorEvaluation;

use App\Data\DetectorSignal;
use App\Enums\DetectorSurface;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\SectionPublication\SongPublicationBoundaryEvidenceService;

/**
 * Reads the risks the boundary evidence pass recorded against a song section.
 *
 * **Why this exists separately from {@see SongPublicationReviewSignals}.** Both
 * read the same section's metadata, and the review adapter already touches this
 * key — but only to ask whether it exists, as its discriminator between
 * "assessed and clear" and "never assessed". It never reads `risks`. So until
 * 2026-09-21 nine risk kinds were written by production and read by nobody in
 * the harness, including {@see \App\Services\ChurchService\SectionPublication\SongLoopedTranscript}'s
 * `song_looped_transcript`, which is §4.3a's song-loop class over 226 sections.
 *
 * The two surfaces are not merged because they make different claims. The review
 * policy objects to publishing a clip as a song; boundary evidence records what
 * the cut can be shown to contain. A section can be release eligible by one and
 * risky by the other, and scoring them as one detector would let either hide
 * inside the other's numbers.
 *
 * **A read, not a re-run**, exactly as the other four adapters are. Re-deriving
 * the evidence here would need each run's own staging context for transcript and
 * RMS inputs, and would hand back a fresher opinion from newer code to be scored
 * as though it had shipped.
 *
 * **Absence is unknown, never clean.** A section with no evidence key was never
 * assessed. A section whose evidence records no risks was assessed and found
 * clear, and that is the positive claim the detector-negative population needs;
 * `decision` says which of the two the pass itself concluded.
 *
 * **Version is surfaced, not resolved.** Evidence is stamped with
 * {@see SongPublicationBoundaryEvidenceService::VERSION}, and §4.3a has already
 * been burned by banked verdicts standing in for current-policy assessment. This
 * adapter reports the stored version in context so evidence older than the rule
 * that produced it is visible rather than silently scored as current.
 */
class SongBoundaryEvidenceSignals
{
    /**
     * Risk kinds that record the pass being unable to look, rather than a
     * finding about the cut.
     *
     * They are still signals — an unreadable input is a real fact about a
     * section — but they are catalogued at S4 so they land in the harness's
     * unassessable column instead of counting against recall. Treating "we could
     * not measure this" as "we measured it and it was clean" is the single
     * mistake H10a's coverage screen was built to avoid.
     *
     * @var list<string>
     */
    public const UNASSESSABLE_KINDS = [
        'song_boundary_evidence_unavailable',
        'song_boundary_evidence_unreadable',
    ];

    /**
     * Every recorded boundary risk across this run's sections.
     *
     * @return list<DetectorSignal>
     */
    public function for(MediaProcessingLog $run): array
    {
        $signals = [];

        foreach ($run->serviceSections()->get() as $section) {
            $signals = [...$signals, ...($this->forSection($run, $section) ?? [])];
        }

        return $signals;
    }

    /**
     * The risks recorded against one section, or null when the boundary pass has
     * never assessed it.
     *
     * @return list<DetectorSignal>|null
     */
    public function forSection(MediaProcessingLog $run, ServiceSection $section): ?array
    {
        $evidence = $this->evidenceFor($section);

        if ($evidence === null) {
            return null;
        }

        $risks = $evidence['risks'] ?? null;

        if (! is_array($risks) || $risks === []) {
            return [];
        }

        $version = is_int($evidence['version'] ?? null) ? $evidence['version'] : null;
        $decision = is_string($evidence['decision'] ?? null) ? $evidence['decision'] : null;

        $signals = [];

        foreach ($risks as $risk) {
            if (! is_array($risk) || ! is_string($risk['kind'] ?? null)) {
                continue;
            }

            $signals[] = DetectorSignal::forStoredSignal(
                surface: DetectorSurface::SongBoundaryEvidence,
                signal: $risk['kind'],
                runId: (int) $run->id,
                sectionId: (int) $section->id,
                start: $section->start_time,
                end: $section->end_time,
                held: (bool) $section->needs_manual_review,
                context: [
                    'detail' => is_string($risk['detail'] ?? null) ? $risk['detail'] : null,
                    'evidence_version' => $version,
                    'decision' => $decision,
                ],
            );
        }

        return $signals;
    }

    /**
     * Whether this section is usable as detector-negative evidence: the boundary
     * pass assessed it and recorded no risk at all.
     */
    public function isDetectorNegative(ServiceSection $section): bool
    {
        $evidence = $this->evidenceFor($section);

        return $evidence !== null && ($evidence['risks'] ?? []) === [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function evidenceFor(ServiceSection $section): ?array
    {
        $metadata = $section->metadata?->toArray() ?? [];
        $evidence = $metadata[SongPublicationBoundaryEvidenceService::METADATA_KEY] ?? null;

        return is_array($evidence) ? $evidence : null;
    }
}
