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
use App\Services\Song\SongLyricIdentityCheck;
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
 * **Only when the evidence changes.** A record is re-checked only when the transcript
 * its check read has been rewritten since — its `transcript_sha256` differs from the
 * stored transcript's, or is {@see self::SUPERSEDED} — or, for a lyric comparison, when
 * the section is now bound to a different song. A record with no fingerprint was found
 * on a transcript nobody could read, so whether it has been repaired is unknown and it
 * is left for a person. A failed re-check records the fingerprint and binding it read,
 * so it is not repeated until one of them changes.
 *
 * **Never weaker than what raised it.** Clearing re-runs the code check, so a hold is
 * recheckable only when that check is at least as strict as whatever found it. Short
 * loops and cadence hallucinations sit below the repetition screen's floors and were
 * confirmed against the source audio, so they are {@see ContentHoldCheck::SourceAudio},
 * not {@see ContentHoldCheck::LoopScreen}. A loop hold clears only when the screen finds
 * no block in the section at all, where the song-loop census needed half of it; a lyric
 * hold clears only on {@see SongLyricIdentityCheck::confirms()}, the census scorer's
 * positive reading of the bound song.
 *
 * Only {@see ContentHoldCheck::isRecheckable()} checks run. Decisions and
 * judgements stay live until someone re-decides them, and the section stays held
 * while any record does. It runs after structure detection and again after song
 * matching, so a lyric comparison reads the run's settled bindings.
 */
class ContentHoldRechecker
{
    /** The transcript the hold was found on is known to have been replaced since. */
    public const SUPERSEDED = 'superseded';

    public function __construct(
        private readonly ServiceTranscriptRepetitionScreen $repetitionScreen,
        private readonly SongLyricIdentityCheck $lyricIdentity,
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
                || ! $this->evidenceChanged($record, $check, $section, $fingerprint)) {
                continue;
            }

            [$passes, $detail] = $this->run($check, $section, $transcript);

            if ($passes) {
                $records[$index] = [...$record, 'cleared_at' => $now, 'cleared_by' => $check->value, 'cleared_reason' => $detail];
                $outcome['cleared']++;

                continue;
            }

            $records[$index] = [
                ...$record,
                'rechecked_at' => $now,
                'recheck_result' => $detail,
                'transcript_sha256' => $fingerprint,
                'song_id' => $section->resolvedSongId(),
            ];
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
     * Whether the evidence the check reads has changed since the record last read it.
     *
     * A record with no fingerprint was found on a transcript nobody could read, so
     * whether it has been repaired is unknown and it waits for a person. Otherwise the
     * transcript being rewritten is a change. A lyric comparison also reads the song
     * the section is bound to, so a binding decision is a change too; a record from
     * before bindings were recorded has not read one, and is checked once.
     *
     * @param  array<string, mixed>  $record
     */
    private function evidenceChanged(array $record, ContentHoldCheck $check, ServiceSection $section, string $fingerprint): bool
    {
        if (! is_string($record['transcript_sha256'] ?? null)) {
            return false;
        }

        if ($record['transcript_sha256'] !== $fingerprint) {
            return true;
        }

        return $check === ContentHoldCheck::LyricComparison
            && (! array_key_exists('song_id', $record) || $record['song_id'] !== $section->resolvedSongId());
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
     * The section's sung words positively name its bound song, read by the scorer that
     * raised the lyric holds ({@see SongLyricIdentityCheck}, the ported 09-14 census rule).
     *
     * It clears on {@see SongLyricIdentityCheck::confirms()}, never on the absence of a
     * contradiction: Whisper drops much singing, and a reading too thin to contradict the
     * binding is too thin to vouch for it.
     *
     * @return array{0: bool, 1: string}
     */
    private function lyricComparison(ServiceSection $section, ChurchServiceTranscript $transcript): array
    {
        $boundSongId = $section->resolvedSongId();

        if ($boundSongId === null) {
            return [false, 'The section is bound to no song, so its lyrics have nothing to agree with.'];
        }

        $assessment = $this->lyricIdentity->assess($transcript, (float) $section->start_time, (float) $section->end_time, $boundSongId);

        if (SongLyricIdentityCheck::confirms($assessment)) {
            return [true, sprintf('The sung words now name the bound song (#%d) with %d shared word pairs.', $boundSongId, $assessment['bound_score']['word_pairs'])];
        }

        return [false, match (true) {
            $assessment['verdict'] === SongLyricIdentityCheck::CONTRADICTED => sprintf('The sung words still name song #%d, not the bound #%d.', (int) $assessment['rival_song_id'], $boundSongId),
            $assessment['verdict'] === SongLyricIdentityCheck::INSUFFICIENT_WORDS => sprintf('The transcript heard %d distinct sung words, too few to name any song.', $assessment['words']),
            default => sprintf('The sung words do not clearly name the bound song #%d (%d shared word pairs).', $boundSongId, $assessment['bound_score']['word_pairs']),
        }];
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
