<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Services\Media\Audio\RmsAnalysisService;

/**
 * A stretch of a talk where the source itself carried no audio.
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
 * Nothing downstream can put the words back, so the flag is the outcome: the talk goes to
 * review for an operator to accept or exclude. It is registered as non-disqualifying in
 * {@see \App\Support\SermonAutoExtractionPolicy}, because the cut is not in question and the
 * operator decides with the media in hand.
 */
class AudioDropoutInsideTalk
{
    public const float DROPOUT_LEVEL_DB = -80.0;

    public const float MINIMUM_SECONDS = 15.0;

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

        $dropouts = $this->dropouts($this->rmsAnalysisService->extractRmsData($rmsLogContent));

        if ($dropouts === []) {
            return $structure;
        }

        $sections = $structure->sections;

        foreach ($structure->sections as $index => $section) {
            if (! in_array($section->type, self::TALK_TYPES, true)) {
                continue;
            }

            $notes = [];

            foreach ($dropouts as [$from, $to]) {
                if (min($section->endTime, $to) - max($section->startTime, $from) >= self::MINIMUM_SECONDS) {
                    $notes[] = sprintf(
                        'Source audio drops out %.0f–%.0f s inside the talk (%.0f s at or below %.0f dB).',
                        $from,
                        $to,
                        $to - $from,
                        self::DROPOUT_LEVEL_DB,
                    );
                }
            }

            if ($notes !== []) {
                $sections[$index] = $section->withReviewFlags([ServiceStructureValidator::FLAG_TALK_AUDIO_DROPOUT], $notes);
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
     * Maximal runs of samples at or below the dropout level lasting the minimum or longer. A run
     * ends at the first sample back above it, or at the last sample of the log.
     *
     * @param  list<array{time: float, rms: float}>  $samples
     * @return list<array{0: float, 1: float}>
     */
    private function dropouts(array $samples): array
    {
        $dropouts = [];
        $start = null;
        $last = null;

        foreach ($samples as ['time' => $time, 'rms' => $level]) {
            $last = $time;

            if ($level <= self::DROPOUT_LEVEL_DB) {
                $start ??= $time;

                continue;
            }

            if ($start !== null && $time - $start >= self::MINIMUM_SECONDS) {
                $dropouts[] = [$start, $time];
            }

            $start = null;
        }

        if ($start !== null && $last !== null && $last - $start >= self::MINIMUM_SECONDS) {
            $dropouts[] = [$start, $last];
        }

        return $dropouts;
    }
}
