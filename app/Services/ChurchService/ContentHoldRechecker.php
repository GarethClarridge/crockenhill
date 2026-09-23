<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Actions\HoldSectionForContentReview;
use App\Data\ChurchServiceTranscript;
use App\Data\ServiceSectionMetadata;
use App\Enums\ContentHoldCheck;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\Media\Audio\ServiceTranscriptRepetitionScreen;
use App\Services\Song\SongLyricsMatchingService;
use App\Support\SectionReviewFlagPolicy;
use Carbon\CarbonImmutable;

/**
 * Re-run, after a repair, the check that found each content hold, and clear the
 * holds it no longer finds.
 *
 * Operator ruling 2026-09-23. The 181 content holds were made by mechanical
 * checks, not by anyone listening, so the check that made a hold can usually
 * re-test it; leaving every one for a person to clear left 1287's two "wrong song"
 * holds standing on content its re-transcription had already repaired.
 *
 * **Only after a repair.** A record is re-checked only when the transcript its
 * check read has been rewritten since — its `transcript_sha256` differs from the
 * stored transcript's, or is {@see self::SUPERSEDED}. A record with no fingerprint
 * was found on a transcript nobody could read, so whether it has been repaired is
 * unknown and it is left for a person. Many holds were raised by census screens that saw more than
 * the code check does (a listened adjudication, a lower threshold), so re-running
 * the code check over the same evidence would release defects nobody repaired. A
 * failed re-check records the fingerprint it read, so it is not repeated until the
 * next repair.
 *
 * Only {@see ContentHoldCheck::isRecheckable()} checks run. Decisions and
 * judgements stay live until someone re-decides them, and the section stays held
 * while any record does.
 */
class ContentHoldRechecker
{
    /** The transcript the hold was found on is known to have been replaced since. */
    public const SUPERSEDED = 'superseded';

    public function __construct(
        private readonly ServiceTranscriptRepetitionScreen $repetitionScreen,
        private readonly SongLyricsMatchingService $lyricsMatcher,
    ) {}

    /**
     * @param  bool  $dryRun  Run the checks and count, but write nothing
     * @return array{cleared: int, kept: int}
     */
    public function recheck(MediaProcessingLog $run, bool $dryRun = false): array
    {
        $outcome = ['cleared' => 0, 'kept' => 0];

        $contents = $run->storedServiceTranscriptContents();

        if ($contents === null) {
            return $outcome;
        }

        $fingerprint = hash('sha256', $contents);
        $transcript = $this->decode($contents);

        if ($transcript === null) {
            return $outcome;
        }

        $sections = $run->serviceSections()->with('churchServiceItem')->get()
            ->filter(static fn (ServiceSection $section): bool => HoldSectionForContentReview::isHeld($section->metadata->reviewFlags ?? []));

        foreach ($sections as $section) {
            $sectionOutcome = $this->recheckSection($section, $transcript, $fingerprint, $dryRun);
            $outcome['cleared'] += $sectionOutcome['cleared'];
            $outcome['kept'] += $sectionOutcome['kept'];
        }

        return $outcome;
    }

    /**
     * @return array{cleared: int, kept: int}
     */
    private function recheckSection(ServiceSection $section, ChurchServiceTranscript $transcript, string $fingerprint, bool $dryRun): array
    {
        $outcome = ['cleared' => 0, 'kept' => 0];
        $metadata = $section->metadata?->toArray() ?? [];
        $records = HoldSectionForContentReview::holdsIn($metadata);
        $now = CarbonImmutable::now()->toIso8601String();

        foreach ($records as $index => $record) {
            $check = ContentHoldCheck::tryFrom(is_string($record['found_by'] ?? null) ? $record['found_by'] : '');

            if (! HoldSectionForContentReview::isLive($record)
                || $check === null
                || ! $check->isRecheckable()
                || ! is_string($record['transcript_sha256'] ?? null)
                || $record['transcript_sha256'] === $fingerprint) {
                continue;
            }

            [$passes, $detail] = $this->run($check, $section, $transcript);

            if ($passes) {
                $records[$index] = [...$record, 'cleared_at' => $now, 'cleared_by' => $check->value, 'cleared_reason' => $detail];
                $outcome['cleared']++;

                continue;
            }

            $records[$index] = [...$record, 'rechecked_at' => $now, 'recheck_result' => $detail, 'transcript_sha256' => $fingerprint];
            $outcome['kept']++;
        }

        if ($dryRun || $outcome === ['cleared' => 0, 'kept' => 0]) {
            return $outcome;
        }

        $metadata[HoldSectionForContentReview::METADATA_KEY] = $records;

        $stillHeld = array_filter($records, HoldSectionForContentReview::isLive(...)) !== [];

        if (! $stillHeld) {
            $metadata['review_flags'] = array_values(array_diff($section->metadata->reviewFlags ?? [], [HoldSectionForContentReview::FLAG]));
        }

        $section->metadata = ServiceSectionMetadata::fromArray($metadata);
        $section->needs_manual_review = SectionReviewFlagPolicy::requiresManualReview(
            $section->section_type,
            is_array($metadata['review_flags'] ?? null) ? $metadata['review_flags'] : [],
            is_string($metadata['sermon_reference'] ?? null) ? $metadata['sermon_reference'] : null,
        );
        $section->save();

        return $outcome;
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function run(ContentHoldCheck $check, ServiceSection $section, ChurchServiceTranscript $transcript): array
    {
        return match ($check) {
            ContentHoldCheck::LoopScreen => $this->loopScreen($section, $transcript),
            ContentHoldCheck::LyricComparison => $this->lyricComparison($section, $transcript),
            default => [false, "{$check->value} is not a check in code."],
        };
    }

    /**
     * The repetition screen over the rewritten transcript finds no block inside the
     * section. Songs are judged the same way: a chorus that repeats keeps its hold,
     * which costs a look, where reading the chorus as clean could release a loop.
     *
     * @return array{0: bool, 1: string}
     */
    private function loopScreen(ServiceSection $section, ChurchServiceTranscript $transcript): array
    {
        $blocks = $this->repetitionScreen->within(
            $this->repetitionScreen->screen($transcript),
            [['start' => (float) $section->start_time, 'end' => (float) $section->end_time]],
        );

        return $blocks === []
            ? [true, 'The repetition screen finds no loop inside the section in the rewritten transcript.']
            : [false, sprintf('The repetition screen still finds %d block(s) inside the section.', count($blocks))];
    }

    /**
     * The section's sung words, matched against the catalogue, name its bound song.
     *
     * @return array{0: bool, 1: string}
     */
    private function lyricComparison(ServiceSection $section, ChurchServiceTranscript $transcript): array
    {
        $boundSongId = $section->resolvedSongId();

        if ($boundSongId === null) {
            return [false, 'The section is bound to no song, so its lyrics have nothing to agree with.'];
        }

        $match = $this->lyricsMatcher->matchFromLyrics(
            $transcript->sliceText((float) $section->start_time, (float) $section->end_time),
            allowFirstLineKeyMatch: false,
        );

        if ($match['song_id'] === $boundSongId) {
            return [true, sprintf('The sung words now match the bound song (#%d) at %.2f.', $boundSongId, $match['confidence'])];
        }

        return [false, $match['song_id'] === null
            ? 'The sung words match no catalogue song.'
            : sprintf('The sung words still match song #%d, not the bound #%d.', $match['song_id'], $boundSongId)];
    }

    private function decode(string $contents): ?ChurchServiceTranscript
    {
        try {
            $transcript = ChurchServiceTranscript::fromArray(json_decode($contents, true, 512, JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            return null;
        }

        return $transcript->isEmpty() ? null : $transcript;
    }
}
