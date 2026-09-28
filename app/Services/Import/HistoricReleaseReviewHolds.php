<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Models\SongVideo;
use App\Services\ChurchService\Structure\ServiceStructureValidator;

/**
 * Whether the records a release batch names are still under content review.
 *
 * P8-Q16 gap 3. Release authorisation checked signature, operation state,
 * ownership, quarantine, disks and hashes — every property of the *batch* — and
 * never once asked whether the content it was about to make public was held for
 * review. A manual flag that release ignores does not hold anything back, so the
 * queue was advisory at the only point where it needed to be binding.
 *
 * Read-only by construction. It never calls `requiresApproval()`, which is not
 * the predicate its name suggests: the song handler writes review reasons onto
 * the section as a side effect, so asking it here would mutate the very records
 * a refused release is supposed to leave untouched. The stored
 * `needs_manual_review` column is the same fact the review queue shows an
 * operator, and agreeing with that is the point.
 *
 * A record whose run an operator excluded is refused the same way. Exclusion
 * leaves the run's rows where they are, so release is the one place it binds.
 */
class HistoricReleaseReviewHolds
{
    /**
     * Flags that question *where the sermon's material lies*, wherever on the
     * run they land — except a micro-section, which counts only within a
     * clip's reach ({@see self::withinReachOfPublishedSpeech()}).
     *
     * A sermon is not represented by its own section alone. The historic corpus
     * contains preaching buried inside an over-long "song" — run 1040's §1511
     * and run 1198's §2486 are both published today while the section holding
     * their missing minutes is flagged — so a gate that only asks "is my own
     * section held?" passes exactly the cases P8-Q15 catalogued. Measured over
     * the current quarantine this widens the refusal from 170 sermons to 188.
     *
     * Deliberately excludes {@see ServiceStructureValidator::FLAG_LOW_CONFIDENCE}.
     * It would block a further 32 sermons while catching none of the proven
     * omissions, which carry no flag at all: breadth, not coverage. A song whose
     * title marker did not match cannot move a sermon boundary, and a gate wide
     * enough to be routed around enforces nothing.
     *
     * @var list<string>
     */
    public const SpanQuestioningFlags = [
        ServiceStructureValidator::FLAG_MACRO_SECTION,
        ServiceStructureValidator::FLAG_MICRO_SECTION,
        ServiceStructureValidator::FLAG_SERMON_INTERRUPTION_MERGED,
        ServiceStructureValidator::FLAG_SERMON_BOUNDARY_MATERIAL_RISK,
    ];

    /**
     * The section types whose review holds speak for a sermon record directly.
     *
     * @var list<ServiceSectionType>
     */
    private const SpokenContentTypes = [
        ServiceSectionType::Sermon,
        ServiceSectionType::ShortTalk,
    ];

    /**
     * The section types a published clip is cut from or paired with.
     *
     * @var list<ServiceSectionType>
     */
    private const ClipAnchorTypes = [
        ServiceSectionType::Sermon,
        ServiceSectionType::ShortTalk,
        ServiceSectionType::BibleReading,
    ];

    /**
     * Every reason this batch may not be released, one line per held record.
     *
     * An empty list is the only clearance. Callers must treat it as such rather
     * than counting: a record with no discoverable sections is reported, not
     * skipped, because unknown evidence is not the same as settled evidence.
     *
     * @param  list<Sermon>  $sermons
     * @param  list<SongVideo>  $songVideos
     * @return list<string>
     */
    public function assess(array $sermons, array $songVideos): array
    {
        return [
            ...$this->sermonExclusions($sermons),
            ...$this->songVideoExclusions($songVideos),
            ...$this->sermonHolds($sermons),
            ...$this->songVideoHolds($songVideos),
        ];
    }

    /**
     * An exclusion is recorded on the run, but the sermon it created was already
     * in quarantine and nothing withdraws it. Without this, a funeral or a
     * rehearsal becomes public simply by being named in a batch.
     *
     * Any excluded run speaks for the sermon: a later run of the same sermon does
     * not undo an operator's ruling about the recording.
     *
     * @param  list<Sermon>  $sermons
     * @return list<string>
     */
    private function sermonExclusions(array $sermons): array
    {
        if ($sermons === []) {
            return [];
        }

        $runs = MediaProcessingLog::query()
            ->whereIn('sermon_id', array_map(static fn (Sermon $sermon): int => $sermon->id, $sermons))
            ->orderBy('id')
            ->get(['id', 'sermon_id', 'processing_id', 'processing_metadata']);

        $exclusions = [];

        foreach ($runs as $run) {
            $reason = $run->exclusionReason();

            if ($reason === null) {
                continue;
            }

            $exclusions[] = "Sermon {$run->sermon_id} is excluded: run {$run->processing_id} is excluded as {$reason}.";
        }

        return $exclusions;
    }

    /**
     * A song video reaches its run through the section it names.
     *
     * @param  list<SongVideo>  $songVideos
     * @return list<string>
     */
    private function songVideoExclusions(array $songVideos): array
    {
        $sectionIds = array_values(array_filter(array_map(
            static fn (SongVideo $video): ?int => $video->service_section_id,
            $songVideos,
        )));

        if ($sectionIds === []) {
            return [];
        }

        /** @var array<int, int> $runIdBySection */
        $runIdBySection = ServiceSection::query()
            ->whereIn('id', $sectionIds)
            ->whereNotNull('media_processing_log_id')
            ->pluck('media_processing_log_id', 'id')
            ->all();

        $excludedRuns = MediaProcessingLog::query()
            ->whereIn('id', array_values(array_unique($runIdBySection)))
            ->get(['id', 'processing_id', 'processing_metadata'])
            ->filter(static fn (MediaProcessingLog $run): bool => $run->isExcluded())
            ->keyBy('id');

        $exclusions = [];

        foreach ($songVideos as $video) {
            $run = $excludedRuns->get($runIdBySection[$video->service_section_id] ?? 0);

            if (! $run instanceof MediaProcessingLog) {
                continue;
            }

            $exclusions[] = "Song video {$video->id} is excluded: run {$run->processing_id} is excluded as {$run->exclusionReason()}.";
        }

        return $exclusions;
    }

    /**
     * @param  list<Sermon>  $sermons
     * @return list<string>
     */
    private function sermonHolds(array $sermons): array
    {
        if ($sermons === []) {
            return [];
        }

        $sermonIds = array_map(static fn (Sermon $sermon): int => $sermon->id, $sermons);

        /**
         * A quarantined sermon has no `published_service_section` yet — that
         * link is written *at* publication — so the obvious relation reads as
         * "no sections held" for every record a release could ever name. The
         * run is the only join that exists before release.
         *
         * @var array<int, int> $sermonIdByLog
         */
        $sermonIdByLog = MediaProcessingLog::query()
            ->whereIn('sermon_id', $sermonIds)
            ->pluck('sermon_id', 'id')
            ->all();

        if ($sermonIdByLog === []) {
            return array_map(
                static fn (int $id): string => "Sermon {$id} has no processing run, so its review state cannot be established.",
                $sermonIds,
            );
        }

        $holds = [];

        $sectionsByLog = ServiceSection::query()
            ->whereIn('media_processing_log_id', array_keys($sermonIdByLog))
            ->orderBy('media_processing_log_id')
            ->orderBy('start_time')
            ->get()
            ->groupBy('media_processing_log_id');

        foreach ($sectionsByLog as $logId => $runSections) {
            $sermonId = $sermonIdByLog[$logId] ?? null;

            if ($sermonId === null) {
                continue;
            }

            foreach ($runSections->where('needs_manual_review', true) as $section) {
                $reason = $this->sermonHoldReason($section, array_values($runSections->all()));

                if ($reason === null) {
                    continue;
                }

                $holds[] = "Sermon {$sermonId} is held for review: {$reason}.";
            }
        }

        return $holds;
    }

    /**
     * Why this held section speaks for the sermon, or null where it does not.
     *
     * @param  list<ServiceSection>  $runSections  every section of the run, in time order
     */
    private function sermonHoldReason(ServiceSection $section, array $runSections): ?string
    {
        $flags = $section->metadata->reviewFlags ?? [];
        $described = $flags === [] ? 'no recorded flag' : implode(', ', $flags);

        if (in_array($section->section_type, self::SpokenContentTypes, true)) {
            return "section {$section->id} ({$section->section_type->value}) carries {$described}";
        }

        $spanFlags = array_values(array_intersect($flags, self::SpanQuestioningFlags));

        if (! $this->withinReachOfPublishedSpeech($section, $runSections)) {
            $spanFlags = array_values(array_diff($spanFlags, [ServiceStructureValidator::FLAG_MICRO_SECTION]));
        }

        if ($spanFlags === []) {
            return null;
        }

        return sprintf(
            'section %d (%s) questions the sermon span with %s',
            $section->id,
            $section->section_type->value,
            implode(', ', $spanFlags),
        );
    }

    /**
     * Whether a published clip could reach this section: no song stands between
     * it and a sermon, talk or reading.
     *
     * Songs bound every spoken clip — a sermon's published span runs on to the
     * next song and no further — so a brief welcome before the first hymn, or
     * notices after the last, cannot move one. Of 91 micro-section flags in the
     * 2026-09-28 detection draws, 90 were welcomes, notices, prayers or other.
     * A macro section keeps its run-wide reach: the preaching it hides is what
     * it is flagged for (run 1040).
     *
     * @param  list<ServiceSection>  $runSections
     */
    private function withinReachOfPublishedSpeech(ServiceSection $section, array $runSections): bool
    {
        $position = array_search($section->id, array_map(static fn (ServiceSection $candidate): int => $candidate->id, $runSections), true);

        if ($position === false) {
            return true;
        }

        foreach ([array_reverse(array_slice($runSections, 0, $position)), array_slice($runSections, $position + 1)] as $direction) {
            foreach ($direction as $neighbour) {
                if ($neighbour->section_type === ServiceSectionType::Song) {
                    break;
                }

                if (in_array($neighbour->section_type, self::ClipAnchorTypes, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A song video names its own section, so its hold needs no inference.
     *
     * @param  list<SongVideo>  $songVideos
     * @return list<string>
     */
    private function songVideoHolds(array $songVideos): array
    {
        if ($songVideos === []) {
            return [];
        }

        $sectionIds = array_values(array_filter(array_map(
            static fn (SongVideo $video): ?int => $video->service_section_id,
            $songVideos,
        )));

        if ($sectionIds === []) {
            return [];
        }

        $held = ServiceSection::query()
            ->whereIn('id', $sectionIds)
            ->where('needs_manual_review', true)
            ->pluck('id')
            ->all();

        $holds = [];

        foreach ($songVideos as $video) {
            if (! in_array($video->service_section_id, $held, true)) {
                continue;
            }

            $holds[] = "Song video {$video->id} is held for review: section {$video->service_section_id} awaits manual review.";
        }

        return $holds;
    }
}
