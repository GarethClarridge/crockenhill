<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Exceptions\SegmentationException;
use App\Services\Media\Audio\RmsAnalysisService;

/**
 * Singing the transcript cannot see, recovered from the RMS log.
 *
 * Whisper leaves congregational singing as an unobservable window or a "Thank you."/"Amen."
 * loop, so a transcript-only detector either cuts a song section to the lines it did
 * transcribe or returns no section for the song at all. The §4.1b section coverage census
 * (2026-09-14) found eight songs cut short and ten with no section.
 *
 * The level log separates the two without the transcript. Measured over the historic corpus
 * on 2026-09-15, per section against the run's own threshold: song sections are at least 84%
 * active and break below the threshold at most 6.8 times a minute (10th and 90th percentiles);
 * sermons, prayers, readings and notices are at most 81% active and break at least 8.3 times a
 * minute. A speaker pauses between phrases; a congregation singing over instruments does not.
 *
 * Two repairs follow, both confined to time no section holds:
 *  - a song section widens across sustained sound beside it;
 *  - sustained sound beside no song becomes a proposed song section, held for review.
 *
 * Sound running on into a neighbouring prayer or reading is left alone. Fresh audio heard 21
 * of those 38 corpus cases as speech: a leader at a microphone is as loud and unbroken as
 * singing, so the level cannot say where a song gives way to a section already typed.
 */
class SustainedSoundSongSections
{
    public const PROPOSED_TITLE = 'Unidentified singing';

    private const BIN_SECONDS = 5.0;

    /** Six bins: each bin is judged on the 30 s around it, so one breath does not split a song. */
    private const WINDOW_BINS = 6;

    private const MINIMUM_ACTIVE_RATIO = 0.8;

    private const MAXIMUM_PAUSES_PER_MINUTE = 8.0;

    private const MINIMUM_PAUSE_SECONDS = 0.3;

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

    private const MINIMUM_PROPOSAL_SECONDS = 45.0;

    private const PROPOSAL_CONFIDENCE = 0.5;

    public function __construct(private readonly RmsAnalysisService $rmsAnalysisService) {}

    /**
     * @param  string  $rmsLogContent  Raw contents of the rms_log_path artifact
     * @param  bool  $recordingOmitsSongs  A concatenated recording had its songs cut out before assembly
     */
    public function apply(ServiceStructure $structure, string $rmsLogContent, bool $recordingOmitsSongs): ServiceStructure
    {
        if ($recordingOmitsSongs || $structure->isEmpty()) {
            return $structure;
        }

        $sound = $this->sustainedSound($rmsLogContent);

        if ($sound === null) {
            return $structure;
        }

        $sections = $this->widenSongs($structure->sections, $sound);
        $sections = $this->proposeSongs($sections, $sound);

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
     * @param  array{bins: list<bool>, audio_end: float}  $sound
     * @return list<ServiceStructureSection>
     */
    private function widenSongs(array $sections, array $sound): array
    {
        $widened = $sections;

        foreach ($sections as $index => $section) {
            if ($section->type !== ServiceSectionType::Song) {
                continue;
            }

            $start = $this->widenedStart($sections, $index, $sound) ?? $section->startTime;
            $end = $this->widenedEnd($sections, $index, $sound) ?? $section->endTime;
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

            $widened[$index] = $section->withTimes($start, $end, $notes);

            if (max($section->startTime - $start, $end - $section->endTime) > self::REVIEWED_WIDENING_SECONDS) {
                $widened[$index] = $widened[$index]->withReviewFlags([ServiceStructureValidator::FLAG_SONG_WIDENED_TO_SUSTAINED_SOUND]);
            }
        }

        return array_values($widened);
    }

    /**
     * @param  list<ServiceStructureSection>  $sections
     * @param  array{bins: list<bool>, audio_end: float}  $sound
     */
    private function widenedEnd(array $sections, int $index, array $sound): ?float
    {
        $section = $sections[$index];
        $bins = $sound['bins'];
        $lastBin = null;

        for ($bin = (int) floor($section->endTime / self::BIN_SECONDS); $bin < count($bins) && $bins[$bin]; $bin++) {
            $holder = $this->holderAt($sections, ($bin + 0.5) * self::BIN_SECONDS, $index);

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

        $nextStart = $sound['audio_end'];

        foreach ($sections as $otherIndex => $other) {
            if ($otherIndex !== $index && $other->startTime >= $section->endTime) {
                $nextStart = min($nextStart, $other->startTime);
            }
        }

        $end = min(($lastBin + 1) * self::BIN_SECONDS, $nextStart);

        return $end - $section->endTime >= self::MINIMUM_WIDENING_SECONDS ? $end : null;
    }

    /**
     * @param  list<ServiceStructureSection>  $sections
     * @param  array{bins: list<bool>, audio_end: float}  $sound
     */
    private function widenedStart(array $sections, int $index, array $sound): ?float
    {
        $section = $sections[$index];
        $bins = $sound['bins'];
        $firstBin = null;

        for ($bin = (int) ceil($section->startTime / self::BIN_SECONDS) - 1; $bin >= 0 && $bin < count($bins) && $bins[$bin]; $bin--) {
            $holder = $this->holderAt($sections, ($bin + 0.5) * self::BIN_SECONDS, $index);

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

        $start = max($firstBin * self::BIN_SECONDS, $previousEnd);

        return $section->startTime - $start >= self::MINIMUM_WIDENING_SECONDS ? $start : null;
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
     * @param  array{bins: list<bool>, audio_end: float}  $sound
     * @return list<ServiceStructureSection>
     */
    private function proposeSongs(array $sections, array $sound): array
    {
        $firstStart = INF;

        foreach ($sections as $section) {
            $firstStart = min($firstStart, $section->startTime);
        }

        $proposed = [];
        $openBin = null;
        $bins = $sound['bins'];

        for ($bin = 0; $bin <= count($bins); $bin++) {
            $mid = ($bin + 0.5) * self::BIN_SECONDS;
            $unheld = $bin < count($bins)
                && $bins[$bin]
                && $mid > $firstStart
                && ! $this->holderAt($sections, $mid, null) instanceof ServiceStructureSection;

            if ($unheld) {
                $openBin ??= $bin;

                continue;
            }

            if ($openBin !== null) {
                $proposal = $this->proposal($sections, $openBin * self::BIN_SECONDS, $bin * self::BIN_SECONDS, $sound['audio_end']);

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
     */
    private function proposal(array $sections, float $from, float $to, float $audioEnd): ?ServiceStructureSection
    {
        $start = $from;
        $end = min($to, $audioEnd);

        foreach ($sections as $section) {
            if ($section->endTime <= $from + self::BIN_SECONDS) {
                $start = max($start, $section->endTime);
            }

            if ($section->startTime >= $to - self::BIN_SECONDS) {
                $end = min($end, $section->startTime);
            }
        }

        if ($end - $start < self::MINIMUM_PROPOSAL_SECONDS) {
            return null;
        }

        foreach ($sections as $section) {
            if ($section->type === ServiceSectionType::Song
                && (abs($section->endTime - $start) <= self::BIN_SECONDS || abs($section->startTime - $end) <= self::BIN_SECONDS)) {
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

    /**
     * Whether each 5 s bin of the recording lies inside sustained sound, judged on the window
     * around it, and where the audio ends. Null when the log holds no samples.
     *
     * @return array{bins: list<bool>, audio_end: float}|null
     */
    private function sustainedSound(string $rmsLogContent): ?array
    {
        $samples = $this->rmsAnalysisService->extractRmsData($rmsLogContent);

        if ($samples === []) {
            return null;
        }

        try {
            $threshold = (float) $this->rmsAnalysisService->determineThreshold($rmsLogContent)['threshold'];
        } catch (SegmentationException) {
            $threshold = $this->rmsAnalysisService->getRmsThreshold();
        }

        $audioEnd = $samples[count($samples) - 1]['time'];
        $binCount = (int) floor($audioEnd / self::BIN_SECONDS) + 1;
        $active = array_fill(0, $binCount, 0);
        $total = array_fill(0, $binCount, 0);
        $pauses = array_fill(0, $binCount, 0);
        $pauseStart = null;

        foreach ($samples as $sample) {
            $bin = min($binCount - 1, max(0, (int) floor($sample['time'] / self::BIN_SECONDS)));
            $total[$bin]++;

            if ($sample['rms'] <= $threshold) {
                $pauseStart ??= $sample['time'];

                continue;
            }

            $active[$bin]++;

            if ($pauseStart !== null && $sample['time'] - $pauseStart >= self::MINIMUM_PAUSE_SECONDS) {
                $pauses[$bin]++;
            }

            $pauseStart = null;
        }

        $half = intdiv(self::WINDOW_BINS, 2);
        $bins = [];

        for ($bin = 0; $bin < $binCount; $bin++) {
            $from = max(0, $bin - $half);
            $length = min($binCount, $bin + $half) - $from;
            $windowTotal = array_sum(array_slice($total, $from, $length));
            $minutes = $length * self::BIN_SECONDS / 60.0;

            $bins[] = $windowTotal > 0
                && array_sum(array_slice($active, $from, $length)) / $windowTotal >= self::MINIMUM_ACTIVE_RATIO
                && array_sum(array_slice($pauses, $from, $length)) / $minutes <= self::MAXIMUM_PAUSES_PER_MINUTE;
        }

        return ['bins' => $bins, 'audio_end' => $audioEnd];
    }
}
