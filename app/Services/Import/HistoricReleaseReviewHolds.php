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
 */
class HistoricReleaseReviewHolds
{
    /**
     * Flags that question *where the sermon's material lies*, wherever on the
     * run they land.
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
        ServiceSectionType::ChildrensTalk,
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
            ...$this->sermonHolds($sermons),
            ...$this->songVideoHolds($songVideos),
        ];
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

        $sections = ServiceSection::query()
            ->whereIn('media_processing_log_id', array_keys($sermonIdByLog))
            ->where('needs_manual_review', true)
            ->orderBy('media_processing_log_id')
            ->orderBy('start_time')
            ->get();

        foreach ($sections as $section) {
            $sermonId = $sermonIdByLog[$section->media_processing_log_id] ?? null;

            if ($sermonId === null) {
                continue;
            }

            $reason = $this->sermonHoldReason($section);

            if ($reason === null) {
                continue;
            }

            $holds[] = "Sermon {$sermonId} is held for review: {$reason}.";
        }

        return $holds;
    }

    /**
     * Why this held section speaks for the sermon, or null where it does not.
     */
    private function sermonHoldReason(ServiceSection $section): ?string
    {
        $flags = $section->metadata->reviewFlags ?? [];
        $described = $flags === [] ? 'no recorded flag' : implode(', ', $flags);

        if (in_array($section->section_type, self::SpokenContentTypes, true)) {
            return "section {$section->id} ({$section->section_type->value}) carries {$described}";
        }

        $spanFlags = array_values(array_intersect($flags, self::SpanQuestioningFlags));

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
