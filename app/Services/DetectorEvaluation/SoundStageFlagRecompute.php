<?php

declare(strict_types=1);

namespace App\Services\DetectorEvaluation;

use App\Actions\RedetectHistoricServiceStructure;
use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Models\MediaProcessingLog;
use App\Services\ChurchService\Structure\DeadFeedInsideSection;
use App\Services\ChurchService\Structure\MistypedSungSections;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\ChurchService\Structure\SongSpeechEdges;
use App\Services\ChurchService\Structure\SoundStage;
use App\Services\ChurchService\Structure\SungSpanInsideSermon;
use App\Services\ChurchService\Structure\SustainedSoundSongSections;
use App\Services\ChurchService\Structure\ValidationContext;
use App\Services\Media\Audio\ServiceTranscriptReader;

/**
 * Re-derive the sound-stage review flags from banked structure, without
 * re-detecting anything.
 *
 * H7b measured that five promoted detectors have never been applied to the
 * corpus, three of them these: `structure_song_widened_to_sustained_sound`,
 * `structure_unidentified_singing` and `structure_section_reads_as_sung`. The
 * obvious remedy — re-run detection — is the wrong one.
 * {@see RedetectHistoricServiceStructure} spells out why: detection
 * costs a provider call, **replaces the projected sections**, and is not
 * deterministic, so a corpus-wide re-detection would rewrite months of
 * adjudicated boundaries and holds and leave no baseline to tell which runs came
 * back worse.
 *
 * None of that is needed, because these flags are not the detector's.
 * {@see DeadFeedInsideSection}, {@see SustainedSoundSongSections}, {@see SongSpeechEdges},
 * {@see MistypedSungSections} and {@see SungSpanInsideSermon} run *after* detection
 * ({@see SoundStage}), over the structure it
 * produced plus the RMS log and the transcript, and all are pure functions of
 * those inputs. Every eligible run has all three
 * banked. So the flags can be re-derived exactly, with no provider call and no
 * section replaced — which is the harness's own carve-out: re-run a detector
 * class only over frozen inputs that are cheap and deterministic.
 *
 * **Read-only.** This reports what the passes would produce; writing it is a
 * separate operator decision. The point of measuring first is that a forecast on
 * this corpus has been wrong in both directions before — the 2026-09-21 screen
 * application predicted 37 new holds, raised 71, and withdrew 5 nobody had
 * forecast at all.
 *
 * The audio timeline's repairs are not replayed: they move edges and replace
 * sections, which a flag write cannot carry, so the stage runs here without one
 * (music and silence plan §6.5).
 *
 * **Every read happens inside the run's own staging context** ({@see BankedRunInputs}).
 * A run whose inputs cannot be read is reported unassessable, never clean.
 */
class SoundStageFlagRecompute
{
    /**
     * The flags these two passes own.
     *
     * Scoped deliberately: the passes also carry other flags through untouched,
     * and counting one of those as newly gained would attribute another
     * detector's finding to this recompute.
     *
     * @var list<string>
     */
    public const SOUND_STAGE_FLAGS = [
        ServiceStructureValidator::FLAG_SONG_WIDENED_TO_SUSTAINED_SOUND,
        ServiceStructureValidator::FLAG_UNIDENTIFIED_SINGING,
        ServiceStructureValidator::FLAG_SECTION_READS_AS_SUNG,
        ServiceStructureValidator::FLAG_SERMON_CONTAINS_SUNG_SPAN,
        ServiceStructureValidator::FLAG_SONG_SWALLOWS_SPEECH,
        ServiceStructureValidator::FLAG_SERMON_ADJACENT_SPEECH_UNOWNED,
        ServiceStructureValidator::FLAG_TALK_AUDIO_DROPOUT,
        ServiceStructureValidator::FLAG_SONG_OVER_DEAD_FEED,
    ];

    public function __construct(
        private readonly BankedRunInputs $inputs,
        private readonly ServiceTranscriptReader $transcripts,
        private readonly SoundStage $soundStage,
    ) {}

    /**
     * @param  iterable<MediaProcessingLog>  $runs
     * @return array<string, mixed>
     */
    public function over(iterable $runs): array
    {
        $assessed = 0;
        $unassessable = [];
        $gained = [];
        $runsWithGains = [];
        $affected = [];

        foreach ($runs as $run) {
            $outcome = $this->forRun($run, $affected);

            if ($outcome === null) {
                $unassessable[] = (int) $run->id;

                continue;
            }

            $assessed++;

            foreach ($outcome as $flag => $count) {
                $gained[$flag] = ($gained[$flag] ?? 0) + $count;

                if ($count > 0) {
                    $runsWithGains[$flag][(int) $run->id] = true;
                }
            }
        }

        $runCounts = [];

        foreach ($runsWithGains as $flag => $ids) {
            $runCounts[$flag] = count($ids);
        }

        return [
            'runs_assessed' => $assessed,
            'runs_unassessable' => count($unassessable),
            'unassessable_run_ids' => $unassessable,
            'sections_gaining_flag' => $gained,
            'runs_gaining_flag' => $runCounts,
            'affected_sections' => $affected,
        ];
    }

    /**
     * How many sections each sound-stage flag would newly cover on this run, or
     * null when the run's own inputs could not be read.
     *
     * `$affected` collects the identity of each section that would gain a flag —
     * run id, position in the structure, and the flags — so the consequence of
     * writing can be looked up afterwards from a short list rather than by
     * holding every run's RMS log and transcript in memory at once. Doing that
     * the other way around exhausted memory on the first attempt.
     *
     * @param  list<array{run: int, index: int|null, flags: list<string>, start: float, end: float}>  $affected
     * @return array<string, int>|null
     */
    public function forRun(MediaProcessingLog $run, array &$affected = []): ?array
    {
        return $this->inputs->within($run, function () use ($run, &$affected): ?array {
            $structure = $this->inputs->structure($run);
            $rms = $this->inputs->rmsLog($run);
            $transcript = $this->transcripts->tryRead($run);

            if ($structure === null || $rms === null || $transcript === null) {
                return null;
            }

            $before = $this->flagCounts($structure);
            $original = $structure->sections;

            $omitsSongs = ValidationContext::recordingOmitsSongs($run->processing_metadata);

            // Applied in the pipeline's own order.
            $recomputed = $this->soundStage->apply($structure, $rms, $transcript, $omitsSongs, null);

            $claimed = [];

            foreach ($recomputed->sections as $section) {
                // Matched by overlap, never by position. The sound stage
                // *inserts* proposed sections, so every index after an
                // insertion point refers to a different original section — and
                // comparing against the wrong original both invents flags that
                // were already there and misses ones that are new. A dry run
                // caught this before anything was written.
                $originIndex = $this->bestOverlap($section, $original, $claimed);

                if ($originIndex === null) {
                    // Nothing it overlaps: a section the pass proposes rather
                    // than one it annotates.
                    $new = array_values(array_intersect($section->reviewFlags, self::SOUND_STAGE_FLAGS));

                    if ($new !== []) {
                        $affected[] = [
                            'run' => (int) $run->id,
                            'index' => null,
                            'flags' => $new,
                            'start' => $section->startTime,
                            'end' => $section->endTime,
                        ];
                    }

                    continue;
                }

                $claimed[$originIndex] = true;

                $new = array_values(array_intersect(
                    array_diff($section->reviewFlags, $original[$originIndex]->reviewFlags),
                    self::SOUND_STAGE_FLAGS,
                ));

                if ($new !== []) {
                    // The *original* bounds travel with the finding, not the
                    // recomputed ones: widening moves a boundary by design, so
                    // comparing a widened bound against the stored row would
                    // refuse the very findings this exists to record.
                    $affected[] = [
                        'run' => (int) $run->id,
                        'index' => $originIndex,
                        'flags' => $new,
                        'start' => $original[$originIndex]->startTime,
                        'end' => $original[$originIndex]->endTime,
                    ];
                }
            }

            $after = $this->flagCounts($recomputed);
            $gained = [];

            foreach ($after as $flag => $count) {
                $gained[$flag] = max(0, $count - ($before[$flag] ?? 0));
            }

            return $gained;
        });
    }

    /**
     * The original section this recomputed one came from, by greatest temporal
     * overlap, or null when it overlaps none of them.
     *
     * @param  list<ServiceStructureSection>  $original
     * @param  array<int, true>  $claimed
     */
    private function bestOverlap(
        ServiceStructureSection $section,
        array $original,
        array $claimed,
    ): ?int {
        $bestIndex = null;
        $bestOverlap = 0.0;

        foreach ($original as $index => $candidate) {
            if (isset($claimed[$index])) {
                continue;
            }

            $overlap = min($section->endTime, $candidate->endTime)
                - max($section->startTime, $candidate->startTime);

            if ($overlap > $bestOverlap) {
                $bestOverlap = $overlap;
                $bestIndex = $index;
            }
        }

        return $bestIndex;
    }

    /**
     * @return array<string, int>
     */
    private function flagCounts(ServiceStructure $structure): array
    {
        $counts = [];

        foreach ($structure->sections as $section) {
            foreach ($section->reviewFlags as $flag) {
                $counts[$flag] = ($counts[$flag] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
