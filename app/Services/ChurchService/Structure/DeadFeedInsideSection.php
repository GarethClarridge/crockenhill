<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Services\Media\Audio\RmsAnalysisService;
use App\Support\SermonAutoExtractionPolicy;

/**
 * A stretch of a talk or a song where the source itself carried no audio.
 *
 * The §4.1a residue census (2026-09-13, `residue-20260913-dropouts.py`) read every run's RMS
 * log for spans at or below {@see self::DROPOUT_LEVEL_DB} lasting {@see self::MINIMUM_SECONDS}
 * or more inside a sermon or children's talk. Confirmed: run 1089 §1790, whose frames show the
 * preacher mid-sentence. A speaker's pause, even a long one, sits tens of decibels above that
 * floor; only a dead feed reaches it.
 *
 * Replayed read-only over the 443 eligible runs on 2026-09-24
 * (`storage/scratch/dropout-20260924/`): it flags 10 talks, every census hit whose section is
 * still a talk. Run 980's two dropouts now fall in the `other` section before its sermon, and
 * run 1043 is excluded.
 *
 * Nothing downstream can put the words back, so for a talk the flag is the outcome: it goes to
 * review for an operator to accept or exclude. It is registered as non-disqualifying in
 * {@see SermonAutoExtractionPolicy}, because the cut is not in question and the
 * operator decides with the media in hand.
 *
 * Songs were the missing type (music and silence plan §6.5, 2026-09-25). The step 0 scan of 442
 * completed historic runs found 29 song/dropout overlaps in 24 sections: a whole "song" over a
 * dead feed (1050 §1584), a song end overrunning into one (1346 §4377), and interior dropouts.
 * Two strengths of evidence are kept apart. A dropout (≤ −80 dB) is suspicious and is held for an
 * operator. A run of digital zero (every frame `-inf`) means the feed carried no samples at all,
 * so a song edge that runs 15 s or more into one moves back to where the zeros begin, with a
 * note and no hold (operator ruling 2). A song is never split or trimmed around an interior
 * dropout: whether its media is released with the gap is the operator's call.
 *
 * The dropouts are also the barriers the later song rules may not widen or propose across
 * ({@see SustainedSoundSongSections}).
 */
class DeadFeedInsideSection
{
    public const float DROPOUT_LEVEL_DB = -80.0;

    public const float MINIMUM_SECONDS = 15.0;

    /**
     * How near a dropout must come to a song's edge to count as reaching it: an RMS log's last
     * frame falls a fraction of a second short of the recording's end.
     */
    private const float EDGE_TOLERANCE_SECONDS = 1.0;

    private const array TALK_TYPES = [ServiceSectionType::Sermon, ServiceSectionType::ShortTalk];

    public function __construct(private readonly RmsAnalysisService $rmsAnalysisService) {}

    /**
     * @param  string  $rmsLogContent  Raw contents of the rms_log_path artifact
     */
    public function apply(ServiceStructure $structure, string $rmsLogContent): ServiceStructure
    {
        if ($structure->isEmpty()) {
            return $structure;
        }

        $samples = $this->rmsAnalysisService->extractRmsData($rmsLogContent);
        $dropouts = $this->runsOf($samples, fn (float $level): bool => $level <= self::DROPOUT_LEVEL_DB, self::MINIMUM_SECONDS);

        if ($dropouts === []) {
            return $structure;
        }

        $zeroRuns = $this->runsOf($samples, $this->rmsAnalysisService->isDigitalSilence(...), 0.0);
        $sections = $structure->sections;

        foreach ($structure->sections as $index => $section) {
            if (in_array($section->type, self::TALK_TYPES, true)) {
                $sections[$index] = $this->talk($section, $dropouts);
            } elseif ($section->type === ServiceSectionType::Song) {
                $sections[$index] = $this->song($section, $dropouts, $zeroRuns);
            }
        }

        if ($sections === $structure->sections) {
            return $structure;
        }

        return ServiceStructure::fromSections(
            array_values($sections),
            $structure->notes,
            $structure->model,
            $structure->summary,
            $structure->notices,
            $structure->chapterMarkers,
            $structure->sermonAbsence,
        );
    }

    /**
     * Every dropout in the recording: maximal runs at or below the dropout level lasting the
     * minimum or longer.
     *
     * @return list<array{0: float, 1: float}>
     */
    public function dropouts(string $rmsLogContent): array
    {
        return $this->runsOf(
            $this->rmsAnalysisService->extractRmsData($rmsLogContent),
            fn (float $level): bool => $level <= self::DROPOUT_LEVEL_DB,
            self::MINIMUM_SECONDS,
        );
    }

    /**
     * @param  list<array{0: float, 1: float}>  $dropouts
     */
    private function talk(ServiceStructureSection $section, array $dropouts): ServiceStructureSection
    {
        $notes = [];

        foreach ($dropouts as [$from, $to]) {
            if ($this->overlap($section, $from, $to) >= self::MINIMUM_SECONDS) {
                $notes[] = sprintf(
                    'Source audio drops out %.0f–%.0f s inside the talk (%.0f s at or below %.0f dB).',
                    $from,
                    $to,
                    $to - $from,
                    self::DROPOUT_LEVEL_DB,
                );
            }
        }

        return $notes === []
            ? $section
            : $section->withReviewFlags([ServiceStructureValidator::FLAG_TALK_AUDIO_DROPOUT], $notes);
    }

    /**
     * @param  list<array{0: float, 1: float}>  $dropouts
     * @param  list<array{0: float, 1: float}>  $zeroRuns
     */
    private function song(ServiceStructureSection $section, array $dropouts, array $zeroRuns): ServiceStructureSection
    {
        // Every second of dropout inside the song counts toward the whole-song test, even from a
        // dropout that reaches less than the minimum into it: what matters is the live audio left.
        $dead = array_sum(array_map(fn (array $dropout): float => max(0.0, $this->overlap($section, $dropout[0], $dropout[1])), $dropouts));
        $duration = $section->endTime - $section->startTime;

        if ($dead > 0.0 && $duration - $dead < self::MINIMUM_SECONDS) {
            return $section->withReviewFlags([ServiceStructureValidator::FLAG_SONG_OVER_DEAD_FEED], [sprintf(
                'The whole song lies over a dead feed: %.0f of its %.0f s are at or below %.0f dB.',
                $dead,
                $duration,
                self::DROPOUT_LEVEL_DB,
            )]);
        }

        $overlapping = array_values(array_filter(
            $dropouts,
            fn (array $dropout): bool => $this->overlap($section, $dropout[0], $dropout[1]) >= self::MINIMUM_SECONDS,
        ));

        if ($overlapping === []) {
            return $section;
        }

        $start = $section->startTime;
        $end = $section->endTime;
        $moveNotes = [];
        $holdNotes = [];

        foreach ($overlapping as [$from, $to]) {
            if ($to >= $section->endTime - self::EDGE_TOLERANCE_SECONDS) {
                $zeros = $this->zeroRunReachingEnd($section, $zeroRuns);

                if ($zeros !== null) {
                    $end = $zeros;
                    $moveNotes[] = sprintf(
                        'End moved from %.1fs to %.1fs: the feed carried no samples from there (%.0f s of digital silence).',
                        $section->endTime,
                        $zeros,
                        $section->endTime - $zeros,
                    );
                } else {
                    $holdNotes[] = sprintf(
                        'The song runs %.0f s into a dropout from %.0f s at or below %.0f dB that is not digital silence; held rather than cut.',
                        $section->endTime - max($from, $section->startTime),
                        $from,
                        self::DROPOUT_LEVEL_DB,
                    );
                }
            } elseif ($from <= $section->startTime + self::EDGE_TOLERANCE_SECONDS) {
                $zeros = $this->zeroRunReachingStart($section, $zeroRuns);

                if ($zeros !== null) {
                    $start = $zeros;
                    $moveNotes[] = sprintf(
                        'Start moved from %.1fs to %.1fs: the feed carried no samples before it (%.0f s of digital silence).',
                        $section->startTime,
                        $zeros,
                        $zeros - $section->startTime,
                    );
                } else {
                    $holdNotes[] = sprintf(
                        'The song opens with %.0f s of a dropout to %.0f s at or below %.0f dB that is not digital silence; held rather than cut.',
                        min($to, $section->endTime) - $section->startTime,
                        $to,
                        self::DROPOUT_LEVEL_DB,
                    );
                }
            } else {
                $holdNotes[] = sprintf(
                    'Source audio drops out %.0f–%.0f s inside the song (%.0f s at or below %.0f dB); its media would carry the gap.',
                    $from,
                    $to,
                    $to - $from,
                    self::DROPOUT_LEVEL_DB,
                );
            }
        }

        if ($moveNotes !== []) {
            $section = $section->withTimes($start, $end, $moveNotes);
        }

        return $holdNotes === []
            ? $section
            : $section->withReviewFlags([ServiceStructureValidator::FLAG_SONG_OVER_DEAD_FEED], $holdNotes);
    }

    /**
     * Where a run of digital zero lasting the minimum inside the song, and reaching its end,
     * begins; null when there is none.
     *
     * @param  list<array{0: float, 1: float}>  $zeroRuns
     */
    private function zeroRunReachingEnd(ServiceStructureSection $section, array $zeroRuns): ?float
    {
        foreach ($zeroRuns as [$from, $to]) {
            if ($to >= $section->endTime - self::EDGE_TOLERANCE_SECONDS
                && $from > $section->startTime
                && $section->endTime - $from >= self::MINIMUM_SECONDS) {
                return $from;
            }
        }

        return null;
    }

    /**
     * Where a run of digital zero lasting the minimum inside the song, and reaching its start,
     * ends; null when there is none.
     *
     * @param  list<array{0: float, 1: float}>  $zeroRuns
     */
    private function zeroRunReachingStart(ServiceStructureSection $section, array $zeroRuns): ?float
    {
        foreach ($zeroRuns as [$from, $to]) {
            if ($from <= $section->startTime + self::EDGE_TOLERANCE_SECONDS
                && $to < $section->endTime
                && $to - $section->startTime >= self::MINIMUM_SECONDS) {
                return $to;
            }
        }

        return null;
    }

    private function overlap(ServiceStructureSection $section, float $from, float $to): float
    {
        return min($section->endTime, $to) - max($section->startTime, $from);
    }

    /**
     * Maximal runs of samples meeting the predicate lasting the minimum or longer. A run ends at
     * the first sample that does not, or at the last sample of the log.
     *
     * @param  list<array{time: float, rms: float}>  $samples
     * @param  callable(float): bool  $inRun
     * @return list<array{0: float, 1: float}>
     */
    private function runsOf(array $samples, callable $inRun, float $minimumSeconds): array
    {
        $runs = [];
        $start = null;
        $last = null;

        foreach ($samples as ['time' => $time, 'rms' => $level]) {
            $last = $time;

            if ($inRun($level)) {
                $start ??= $time;

                continue;
            }

            if ($start !== null && $time - $start >= $minimumSeconds) {
                $runs[] = [$start, $time];
            }

            $start = null;
        }

        if ($start !== null && $last !== null && $last - $start >= $minimumSeconds) {
            $runs[] = [$start, $last];
        }

        return $runs;
    }
}
