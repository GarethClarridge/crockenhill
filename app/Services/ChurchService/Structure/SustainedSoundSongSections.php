<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Enums\SoundClass;
use App\Services\DetectorEvaluation\SoundStageFlagRecompute;
use App\Services\Media\Audio\AudioTimeline;
use App\Services\Media\Audio\RmsAnalysisService;
use App\Services\Media\Audio\SustainedSound;

/**
 * Singing the transcript cannot see, recovered from the RMS log.
 *
 * Whisper leaves congregational singing as an unobservable window or a "Thank you."/"Amen."
 * loop, so a transcript-only detector either cuts a song section to the lines it did
 * transcribe or returns no section for the song at all. The §4.1b section coverage census
 * (2026-09-14) found eight songs cut short and ten with no section. {@see SustainedSound}
 * tells singing from speech without the transcript.
 *
 * Two repairs follow, both confined to time no section holds:
 *  - a song section widens across sustained sound beside it;
 *  - sustained sound beside no song becomes a proposed song section, held for review.
 *
 * Sound running on into a neighbouring prayer or reading is left alone. Fresh audio heard 21
 * of those 38 corpus cases as speech: a leader at a microphone is as loud and unbroken as
 * singing, so the level cannot say where a song gives way to a section already typed.
 *
 * The audio classifier's timeline can ({@see AudioTimeline}), so two more repairs read it, and
 * only into an interior `other` section — one with a typed section somewhere before it and
 * somewhere after it. Leading and trailing `other` sections hold pre- and post-service music,
 * which stays `other` (operator ruling 5, 2026-09-25):
 *  - a song widens into an adjacent interior `other` across windows heard as music (1028,
 *    1262), held because it moves an edge the detector chose;
 *  - an interior `other` heard as music throughout becomes a proposed song (1304, canary 4).
 * Never into a prayer, reading, sermon or talk: those carry speech over the band.
 *
 * No repair crosses or enters a dropout ({@see DeadFeedInsideSection}): widening bridges short
 * gaps, and a song must not be carried into a dead feed the earlier rule has just cut it back from.
 */
class SustainedSoundSongSections
{
    public const PROPOSED_TITLE = 'Unidentified singing';

    /**
     * Every corpus widening under 30 s that fresh audio heard as speech was 25 s or less; of the
     * 24 unadjudicated widenings of 30 s or more, none was speech.
     */
    private const MINIMUM_WIDENING_SECONDS = 30.0;

    /**
     * A short silence between two songs does not survive the smoothing window, so a long
     * widening can swallow the next song: 1244 §3037 would take 265 s and 985 §1121 160 s of
     * another song. The real truncations widen 40-150 s, so length alone cannot decide, and a
     * widening past this is kept but held for review.
     */
    private const REVIEWED_WIDENING_SECONDS = 90.0;

    /**
     * An announced song's section can hold only the announcement, with a few seconds of
     * introduction before the singing is sustained (1341 §4310, 1231 §2851: 10 s each). The
     * corpus census of 448 runs found no other edge within 20 s of sustained sound, and the one
     * at 20 s (1287 §3597) is a displaced song identity, not an introduction. A bridged widening
     * is always held: it is an inference about the gap, not only about the sound.
     */
    private const INTRODUCTION_BINS = 2;

    private const MINIMUM_PROPOSAL_SECONDS = 45.0;

    private const PROPOSAL_CONFIDENCE = 0.5;

    /**
     * The classifier places every edge of the §2 truth cases within one 5 s window, so a widening
     * of under two windows is inside its resolution and would only hold a song for noise.
     */
    private const MINIMUM_MUSIC_WIDENING_SECONDS = 10.0;

    /**
     * An `other` section heard as music for at least this share of its span, and as speech for
     * no more than {@see self::MAXIMUM_PROPOSAL_SPEECH_SHARE}, is proposed as a song (plan §6.5 R3).
     */
    private const MINIMUM_PROPOSAL_MUSIC_SHARE = 0.8;

    private const MAXIMUM_PROPOSAL_SPEECH_SHARE = 0.2;

    public function __construct(private readonly RmsAnalysisService $rmsAnalysisService) {}

    /**
     * @param  string  $rmsLogContent  Raw contents of the rms_log_path artifact
     * @param  bool  $recordingOmitsSongs  A concatenated recording had its songs cut out before assembly
     * @param  AudioTimeline|null  $audioTimeline  The run's music/speech timeline. Null only where the
     *                                             sections are re-derived from banked structure
     *                                             ({@see SoundStageFlagRecompute}),
     *                                             which replays flags, not the repairs that move them.
     * @param  list<array{0: float, 1: float}>  $barriers  Dropouts no repair may cross or enter
     */
    public function apply(
        ServiceStructure $structure,
        string $rmsLogContent,
        bool $recordingOmitsSongs,
        ?AudioTimeline $audioTimeline = null,
        array $barriers = [],
    ): ServiceStructure {
        if ($recordingOmitsSongs || $structure->isEmpty()) {
            return $structure;
        }

        $sound = $this->sustainedSound($rmsLogContent);

        if (! $sound instanceof SustainedSound) {
            return $structure;
        }

        $sections = $this->widenSongs($structure->sections, $sound, $barriers);

        if ($audioTimeline instanceof AudioTimeline) {
            $sections = $this->widenSongsIntoMusic($sections, $audioTimeline, $barriers);
        }

        $sections = $this->proposeSongs($sections, $sound, $barriers);

        if ($audioTimeline instanceof AudioTimeline) {
            $sections = $this->proposeSongsFromMusic($sections, $audioTimeline, $barriers);
        }

        if ($sections === $structure->sections) {
            return $structure;
        }

        return ServiceStructure::fromSections(
            $sections,
            $structure->notes,
            $structure->model,
            $structure->summary,
            $structure->notices,
            $structure->chapterMarkers,
            $structure->sermonAbsence,
        );
    }

    /**
     * Widen each song across the sustained sound beside it, judged against the sections as
     * detected so that one song's widening never decides another's.
     *
     * @param  list<ServiceStructureSection>  $sections
     * @param  list<array{0: float, 1: float}>  $barriers
     * @return list<ServiceStructureSection>
     */
    private function widenSongs(array $sections, SustainedSound $sound, array $barriers): array
    {
        $widened = $sections;

        foreach ($sections as $index => $section) {
            if ($section->type !== ServiceSectionType::Song) {
                continue;
            }

            [$start, $bridgedStart] = $this->widenedStart($sections, $index, $sound, $barriers) ?? [$section->startTime, false];
            [$end, $bridgedEnd] = $this->widenedEnd($sections, $index, $sound, $barriers) ?? [$section->endTime, false];
            $growth = ($section->startTime - $start) + ($end - $section->endTime);

            if ($growth <= 0.0) {
                continue;
            }

            $notes = [];

            if ($start < $section->startTime) {
                $notes[] = sprintf('Start widened %+.1fs across sustained sound to %.1fs; the transcript shows no singing there.', $start - $section->startTime, $start);
            }

            if ($end > $section->endTime) {
                $notes[] = sprintf('End widened %+.1fs across sustained sound to %.1fs; the transcript shows no singing there.', $end - $section->endTime, $end);
            }

            if ($bridgedStart || $bridgedEnd) {
                $notes[] = 'The widening crossed a short introduction between the announcement and the singing.';
            }

            $widened[$index] = $section->withTimes($start, $end, $notes);

            if ($bridgedStart || $bridgedEnd || max($section->startTime - $start, $end - $section->endTime) > self::REVIEWED_WIDENING_SECONDS) {
                $widened[$index] = $widened[$index]->withReviewFlags([ServiceStructureValidator::FLAG_SONG_WIDENED_TO_SUSTAINED_SOUND]);
            }
        }

        return array_values($widened);
    }

    /**
     * @param  list<ServiceStructureSection>  $sections
     * @param  list<array{0: float, 1: float}>  $barriers
     * @return array{0: float, 1: bool}|null The new end, and whether it crossed an introduction
     */
    private function widenedEnd(array $sections, int $index, SustainedSound $sound, array $barriers): ?array
    {
        $section = $sections[$index];
        $firstBin = $this->firstSustainedBin($sections, $index, $sound, (int) floor($section->endTime / SustainedSound::BIN_SECONDS), 1);

        if ($firstBin === null) {
            return null;
        }

        $lastBin = null;

        for ($bin = $firstBin; $bin < $sound->binCount() && $sound->isSustainedBin($bin); $bin++) {
            $holder = $this->holderAt($sections, ($bin + 0.5) * SustainedSound::BIN_SECONDS, $index);

            if ($holder instanceof ServiceStructureSection) {
                if ($holder->type === ServiceSectionType::Song) {
                    return null;
                }

                break;
            }

            $lastBin = $bin;
        }

        if ($lastBin === null) {
            return null;
        }

        $nextStart = $sound->audioEnd;

        foreach ($sections as $otherIndex => $other) {
            if ($otherIndex !== $index && $other->startTime >= $section->endTime) {
                $nextStart = min($nextStart, $other->startTime);
            }
        }

        $end = min(($lastBin + 1) * SustainedSound::BIN_SECONDS, $nextStart, $this->barrierAfter($section->endTime, $barriers));
        $sustainedFrom = max($section->endTime, $firstBin * SustainedSound::BIN_SECONDS);

        return $end - $sustainedFrom >= self::MINIMUM_WIDENING_SECONDS
            ? [$end, $sustainedFrom > $section->endTime]
            : null;
    }

    /**
     * @param  list<ServiceStructureSection>  $sections
     * @param  list<array{0: float, 1: float}>  $barriers
     * @return array{0: float, 1: bool}|null The new start, and whether it crossed an introduction
     */
    private function widenedStart(array $sections, int $index, SustainedSound $sound, array $barriers): ?array
    {
        $section = $sections[$index];
        $lastBin = $this->firstSustainedBin($sections, $index, $sound, (int) ceil($section->startTime / SustainedSound::BIN_SECONDS) - 1, -1);

        if ($lastBin === null) {
            return null;
        }

        $firstBin = null;

        for ($bin = $lastBin; $bin >= 0 && $bin < $sound->binCount() && $sound->isSustainedBin($bin); $bin--) {
            $holder = $this->holderAt($sections, ($bin + 0.5) * SustainedSound::BIN_SECONDS, $index);

            if ($holder instanceof ServiceStructureSection) {
                if ($holder->type === ServiceSectionType::Song) {
                    return null;
                }

                break;
            }

            $firstBin = $bin;
        }

        if ($firstBin === null) {
            return null;
        }

        $previousEnd = 0.0;

        foreach ($sections as $otherIndex => $other) {
            if ($otherIndex !== $index && $other->endTime <= $section->startTime) {
                $previousEnd = max($previousEnd, $other->endTime);
            }
        }

        $start = max($firstBin * SustainedSound::BIN_SECONDS, $previousEnd, $this->barrierBefore($section->startTime, $barriers));
        $sustainedTo = min($section->startTime, ($lastBin + 1) * SustainedSound::BIN_SECONDS);

        return $sustainedTo - $start >= self::MINIMUM_WIDENING_SECONDS
            ? [$start, $sustainedTo < $section->startTime]
            : null;
    }

    /**
     * The first sustained bin met walking away from a song's edge, across at most an
     * introduction's worth of unheld, unsustained bins.
     *
     * @param  list<ServiceStructureSection>  $sections
     * @param  1|-1  $direction
     */
    private function firstSustainedBin(array $sections, int $index, SustainedSound $sound, int $edgeBin, int $direction): ?int
    {
        for ($step = 0; $step <= self::INTRODUCTION_BINS; $step++) {
            $bin = $edgeBin + $step * $direction;

            if ($bin < 0 || $bin >= $sound->binCount()) {
                return null;
            }

            if ($sound->isSustainedBin($bin)) {
                return $bin;
            }

            if ($this->holderAt($sections, ($bin + 0.5) * SustainedSound::BIN_SECONDS, $index) instanceof ServiceStructureSection) {
                return null;
            }
        }

        return null;
    }

    /**
     * Propose a held song over each run of sustained sound no section holds, after the first
     * section and beside no song.
     *
     * Before the first section is excluded because music before a service is as loud as an
     * opening song. Beside a song, the widening has either taken the sound or refused it: it
     * was too short to trust, or it ran on into another song and belongs to one of the two.
     *
     * @param  list<ServiceStructureSection>  $sections
     * @param  list<array{0: float, 1: float}>  $barriers
     * @return list<ServiceStructureSection>
     */
    private function proposeSongs(array $sections, SustainedSound $sound, array $barriers): array
    {
        $firstStart = INF;

        foreach ($sections as $section) {
            $firstStart = min($firstStart, $section->startTime);
        }

        $proposed = [];
        $openBin = null;

        for ($bin = 0; $bin <= $sound->binCount(); $bin++) {
            $mid = ($bin + 0.5) * SustainedSound::BIN_SECONDS;
            $unheld = $sound->isSustainedBin($bin)
                && $mid > $firstStart
                && ! $this->holderAt($sections, $mid, null) instanceof ServiceStructureSection
                && ! $this->insideBarrier($mid, $barriers);

            if ($unheld) {
                $openBin ??= $bin;

                continue;
            }

            if ($openBin !== null) {
                $proposal = $this->proposal($sections, $openBin * SustainedSound::BIN_SECONDS, $bin * SustainedSound::BIN_SECONDS, $sound->audioEnd, $barriers);

                if ($proposal instanceof ServiceStructureSection) {
                    $proposed[] = $proposal;
                }

                $openBin = null;
            }
        }

        return [...$sections, ...$proposed];
    }

    /**
     * @param  list<ServiceStructureSection>  $sections
     * @param  list<array{0: float, 1: float}>  $barriers
     */
    private function proposal(array $sections, float $from, float $to, float $audioEnd, array $barriers): ?ServiceStructureSection
    {
        $start = max($from, $this->barrierBefore($from + SustainedSound::BIN_SECONDS / 2, $barriers));
        $end = min($to, $audioEnd, $this->barrierAfter($to - SustainedSound::BIN_SECONDS / 2, $barriers));

        foreach ($sections as $section) {
            if ($section->endTime <= $from + SustainedSound::BIN_SECONDS) {
                $start = max($start, $section->endTime);
            }

            if ($section->startTime >= $to - SustainedSound::BIN_SECONDS) {
                $end = min($end, $section->startTime);
            }
        }

        if ($end - $start < self::MINIMUM_PROPOSAL_SECONDS) {
            return null;
        }

        foreach ($sections as $section) {
            if ($section->type === ServiceSectionType::Song
                && (abs($section->endTime - $start) <= SustainedSound::BIN_SECONDS || abs($section->startTime - $end) <= SustainedSound::BIN_SECONDS)) {
                return null;
            }
        }

        return new ServiceStructureSection(
            type: ServiceSectionType::Song,
            title: self::PROPOSED_TITLE,
            startTime: $start,
            endTime: $end,
            confidence: self::PROPOSAL_CONFIDENCE,
            oosItemId: null,
            songTitle: null,
            readingReference: null,
            notes: [sprintf(
                'Proposed over %.0fs of sustained sound no section held (%.1fs-%.1fs); the transcript shows no singing there, so the song is unidentified.',
                $end - $start,
                $start,
                $end,
            )],
            reviewFlags: [ServiceStructureValidator::FLAG_UNIDENTIFIED_SINGING],
        );
    }

    /**
     * Widen each song into an adjacent interior `other` section across the windows the
     * classifier hears as music, stopping at the first window that is not music or that enters
     * a dropout. The neighbour shrinks, and is removed only when nothing of it remains: the music
     * stops short of its far edge at a window that is not music, and that time stays `other`.
     *
     * @param  list<ServiceStructureSection>  $sections
     * @param  list<array{0: float, 1: float}>  $barriers
     * @return list<ServiceStructureSection>
     */
    private function widenSongsIntoMusic(array $sections, AudioTimeline $timeline, array $barriers): array
    {
        $sections = $this->inTimeOrder($sections);
        $removed = [];

        foreach ($sections as $index => $song) {
            if ($song->type !== ServiceSectionType::Song) {
                continue;
            }

            $next = $this->adjacentInteriorOther($sections, $removed, $index, 1);

            if ($next !== null) {
                $end = $this->musicReach($timeline, $song->endTime, $sections[$next]->endTime, 1, $barriers);

                if ($end - $song->endTime >= self::MINIMUM_MUSIC_WIDENING_SECONDS) {
                    $song = $song->withTimes($song->startTime, $end, [sprintf(
                        'End widened %+.1fs to %.1fs into the following "other" section across audio the classifier hears as music.',
                        $end - $song->endTime,
                        $end,
                    )])->withReviewFlags([ServiceStructureValidator::FLAG_SONG_WIDENED_INTO_MUSIC]);

                    if ($end >= $sections[$next]->endTime) {
                        $removed[$next] = true;
                    } else {
                        $sections[$next] = $sections[$next]->withTimes($end, $sections[$next]->endTime);
                    }
                }
            }

            $previous = $this->adjacentInteriorOther($sections, $removed, $index, -1);

            if ($previous !== null) {
                $start = $this->musicReach($timeline, $song->startTime, $sections[$previous]->startTime, -1, $barriers);

                if ($song->startTime - $start >= self::MINIMUM_MUSIC_WIDENING_SECONDS) {
                    $song = $song->withTimes($start, $song->endTime, [sprintf(
                        'Start widened %+.1fs to %.1fs into the preceding "other" section across audio the classifier hears as music.',
                        $start - $song->startTime,
                        $start,
                    )])->withReviewFlags([ServiceStructureValidator::FLAG_SONG_WIDENED_INTO_MUSIC]);

                    if ($start <= $sections[$previous]->startTime) {
                        $removed[$previous] = true;
                    } else {
                        $sections[$previous] = $sections[$previous]->withTimes($sections[$previous]->startTime, $start);
                    }
                }
            }

            $sections[$index] = $song;
        }

        return array_values(array_diff_key($sections, $removed));
    }

    /**
     * Propose a held song in place of each interior `other` section the classifier hears as
     * music throughout, beside no song and over no dropout.
     *
     * @param  list<ServiceStructureSection>  $sections
     * @param  list<array{0: float, 1: float}>  $barriers
     * @return list<ServiceStructureSection>
     */
    private function proposeSongsFromMusic(array $sections, AudioTimeline $timeline, array $barriers): array
    {
        $sections = $this->inTimeOrder($sections);

        foreach ($sections as $index => $section) {
            if ($section->type !== ServiceSectionType::Other
                || ! $this->isInterior($sections, $index)
                || $section->endTime - $section->startTime < self::MINIMUM_PROPOSAL_SECONDS
                || $this->besideSong($sections, $index)
                || $this->overlapsBarrier($section->startTime, $section->endTime, $barriers)) {
                continue;
            }

            $musicShare = $timeline->classShare(SoundClass::Music, $section->startTime, $section->endTime);
            $speechShare = $timeline->speechShare($section->startTime, $section->endTime);

            if ($musicShare < self::MINIMUM_PROPOSAL_MUSIC_SHARE || $speechShare > self::MAXIMUM_PROPOSAL_SPEECH_SHARE) {
                continue;
            }

            $sections[$index] = new ServiceStructureSection(
                type: ServiceSectionType::Song,
                title: self::PROPOSED_TITLE,
                startTime: $section->startTime,
                endTime: $section->endTime,
                confidence: self::PROPOSAL_CONFIDENCE,
                oosItemId: null,
                songTitle: null,
                readingReference: null,
                notes: [...$section->notes, sprintf(
                    'Proposed in place of an "other" section%s (%.1fs-%.1fs): the classifier hears %.0f%% of it as music and %.0f%% as speech, so the song is unidentified.',
                    $section->title === null || trim($section->title) === '' ? '' : ' titled "'.trim($section->title).'"',
                    $section->startTime,
                    $section->endTime,
                    $musicShare * 100,
                    $speechShare * 100,
                )],
                reviewFlags: [...$section->reviewFlags, ServiceStructureValidator::FLAG_UNIDENTIFIED_SINGING],
            );
        }

        return $sections;
    }

    /**
     * How far contiguous music windows reach from an edge towards a limit, stopping at the
     * first window that is not music or that enters a dropout.
     *
     * @param  1|-1  $direction
     * @param  list<array{0: float, 1: float}>  $barriers
     */
    private function musicReach(AudioTimeline $timeline, float $edge, float $limit, int $direction, array $barriers): float
    {
        $reach = $edge;
        $window = $timeline->windowAt($direction === 1 ? $edge : $edge - 0.001);

        while ($window !== null && $window >= 0 && $window < $timeline->windowCount()) {
            ['start' => $start, 'end' => $end] = $timeline->windows[$window];

            if ($timeline->classOf($window) !== SoundClass::Music || $this->overlapsBarrier($start, $end, $barriers)) {
                break;
            }

            if ($direction === 1) {
                $reach = min($end, $limit);

                if ($reach >= $limit) {
                    break;
                }
            } else {
                $reach = max($start, $limit);

                if ($reach <= $limit) {
                    break;
                }
            }

            $window += $direction;
        }

        return $reach;
    }

    /**
     * The neighbour of a song on one side, when it is an interior `other` section starting
     * within a window of the song's edge.
     *
     * @param  array<int, ServiceStructureSection>  $sections  In time order
     * @param  array<int, true>  $removed  Neighbours already taken whole by a widening
     * @param  1|-1  $direction
     */
    private function adjacentInteriorOther(array $sections, array $removed, int $index, int $direction): ?int
    {
        $neighbour = $index + $direction;

        while (isset($removed[$neighbour])) {
            $neighbour += $direction;
        }

        $candidate = $sections[$neighbour] ?? null;

        if (! $candidate instanceof ServiceStructureSection || $candidate->type !== ServiceSectionType::Other) {
            return null;
        }

        $song = $sections[$index];
        $gap = $direction === 1 ? $candidate->startTime - $song->endTime : $song->startTime - $candidate->endTime;

        return $gap <= SustainedSound::BIN_SECONDS && $this->isInterior($sections, $neighbour) ? $neighbour : null;
    }

    /**
     * @param  array<int, ServiceStructureSection>  $sections  In time order
     */
    private function isInterior(array $sections, int $index): bool
    {
        $before = false;
        $after = false;

        foreach ($sections as $other => $section) {
            if ($section->type === ServiceSectionType::Other) {
                continue;
            }

            $before = $before || $other < $index;
            $after = $after || $other > $index;
        }

        return $before && $after;
    }

    /**
     * Whether a song starts or ends within a window of the section, on either side.
     *
     * @param  list<ServiceStructureSection>  $sections  In time order
     */
    private function besideSong(array $sections, int $index): bool
    {
        $section = $sections[$index];

        foreach ($sections as $other => $candidate) {
            if ($other === $index || $candidate->type !== ServiceSectionType::Song) {
                continue;
            }

            if (abs($candidate->endTime - $section->startTime) <= SustainedSound::BIN_SECONDS
                || abs($candidate->startTime - $section->endTime) <= SustainedSound::BIN_SECONDS) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<ServiceStructureSection>  $sections
     * @return list<ServiceStructureSection>
     */
    private function inTimeOrder(array $sections): array
    {
        usort($sections, static fn (ServiceStructureSection $a, ServiceStructureSection $b): int => $a->startTime <=> $b->startTime);

        return $sections;
    }

    /**
     * The start of the first dropout ending after a time, or infinity: no edge may widen past
     * it. A time already inside a dropout returns itself.
     *
     * @param  list<array{0: float, 1: float}>  $barriers
     */
    private function barrierAfter(float $time, array $barriers): float
    {
        $limit = INF;

        foreach ($barriers as [$from, $to]) {
            if ($to > $time) {
                $limit = min($limit, max($from, $time));
            }
        }

        return $limit;
    }

    /**
     * The end of the last dropout starting before a time, or zero: no edge may widen past it. A
     * time already inside a dropout returns itself.
     *
     * @param  list<array{0: float, 1: float}>  $barriers
     */
    private function barrierBefore(float $time, array $barriers): float
    {
        $limit = 0.0;

        foreach ($barriers as [$from, $to]) {
            if ($from < $time) {
                $limit = max($limit, min($to, $time));
            }
        }

        return $limit;
    }

    /**
     * @param  list<array{0: float, 1: float}>  $barriers
     */
    private function insideBarrier(float $time, array $barriers): bool
    {
        foreach ($barriers as [$from, $to]) {
            if ($from <= $time && $time <= $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{0: float, 1: float}>  $barriers
     */
    private function overlapsBarrier(float $from, float $to, array $barriers): bool
    {
        foreach ($barriers as [$barrierFrom, $barrierTo]) {
            if (min($to, $barrierTo) > max($from, $barrierFrom)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<ServiceStructureSection>  $sections
     */
    private function holderAt(array $sections, float $time, ?int $exceptIndex): ?ServiceStructureSection
    {
        foreach ($sections as $index => $section) {
            if ($index !== $exceptIndex && $section->startTime <= $time && $section->endTime > $time) {
                return $section;
            }
        }

        return null;
    }

    private function sustainedSound(string $rmsLogContent): ?SustainedSound
    {
        return SustainedSound::fromRmsLog($rmsLogContent, $this->rmsAnalysisService);
    }
}
