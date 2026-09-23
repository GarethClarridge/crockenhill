<?php

declare(strict_types=1);

namespace App\Services\DetectorEvaluation;

use App\Data\ServiceStructure;
use App\Models\MediaProcessingLog;
use App\Services\ChurchService\Structure\MistypedSungSections;
use App\Services\ChurchService\Structure\SungSpanInsideSermon;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\ChurchService\Structure\SustainedSoundSongSections;
use App\Services\ChurchService\Structure\ValidationContext;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Services\Media\Audio\ServiceTranscriptReader;
use App\Support\ServiceArtifactDisk;
use Closure;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Re-derive the sound-stage review flags from banked structure, without
 * re-detecting anything.
 *
 * H7b measured that five promoted detectors have never been applied to the
 * corpus, three of them these: `structure_song_widened_to_sustained_sound`,
 * `structure_unidentified_singing` and `structure_section_reads_as_sung`. The
 * obvious remedy — re-run detection — is the wrong one.
 * {@see \App\Actions\RedetectHistoricServiceStructure} spells out why: detection
 * costs a provider call, **replaces the projected sections**, and is not
 * deterministic, so a corpus-wide re-detection would rewrite months of
 * adjudicated boundaries and holds and leave no baseline to tell which runs came
 * back worse.
 *
 * None of that is needed, because these three flags are not the detector's.
 * {@see SustainedSoundSongSections} and {@see MistypedSungSections} run *after*
 * detection, over the structure it produced plus the RMS log and the transcript,
 * and both are pure functions of those inputs. Every eligible run has all three
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
 * **Every read happens inside the run's own staging context.** H10a lost a pass
 * to this: reading from the ambient disk reports every run unavailable, which is
 * indistinguishable from a corpus with nothing to find. A run whose inputs
 * cannot be read is reported unassessable, never clean.
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
    ];

    public function __construct(
        private readonly HistoricStagingContextRegistry $stagingContexts,
        private readonly ServiceTranscriptReader $transcripts,
        private readonly SustainedSoundSongSections $sustainedSound,
        private readonly MistypedSungSections $mistypedSung,
        private readonly SungSpanInsideSermon $sungSpanInsideSermon,
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
        return $this->withRunContext($run, function () use ($run, &$affected): ?array {
            $banked = data_get($run->processing_metadata?->toArray() ?? [], 'service_structure');

            if (! is_array($banked)) {
                return null;
            }

            $rms = $this->rmsLogContent($run);
            $transcript = $this->transcripts->tryRead($run);

            if ($rms === null || $transcript === null) {
                return null;
            }

            $structure = ServiceStructure::fromArray($banked);
            $before = $this->flagCounts($structure);
            $original = $structure->sections;

            $omitsSongs = ValidationContext::recordingOmitsSongs($run->processing_metadata);

            // Applied in the pipeline's own order: widening first, so a song
            // that grew across unsectioned singing is judged at its new edges,
            // and the mistyped-sung pass last, so a section still typed as
            // something else is one no song claimed.
            $recomputed = $this->sustainedSound->apply($structure, $rms, $omitsSongs);
            $recomputed = $this->mistypedSung->apply($recomputed, $rms, $transcript);
            $recomputed = $this->sungSpanInsideSermon->apply($recomputed, $rms, $transcript);

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
     * @param  list<\App\Data\ServiceStructureSection>  $original
     * @param  array<int, true>  $claimed
     */
    private function bestOverlap(
        \App\Data\ServiceStructureSection $section,
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

    private function rmsLogContent(MediaProcessingLog $run): ?string
    {
        $path = $run->rms_log_path;

        if (! is_string($path) || $path === '') {
            return null;
        }

        try {
            $disk = Storage::disk(ServiceArtifactDisk::for($path));

            return $disk->exists($path) ? (string) $disk->get($path) : null;
        } catch (Throwable) {
            // An unreadable artifact is unassessable, never clean.
            return null;
        }
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function withRunContext(MediaProcessingLog $run, Closure $callback): mixed
    {
        $context = $run->historicStagingContext();

        if ($context === null) {
            return $callback();
        }

        return $this->stagingContexts->within($context, $callback);
    }
}
