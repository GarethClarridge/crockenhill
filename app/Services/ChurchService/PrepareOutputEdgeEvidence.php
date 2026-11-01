<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Enums\ServiceSectionType;
use App\Jobs\TranscribeOutputEdges;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\Processing\StorageAdapterHelper;
use App\Services\Sermon\SermonExtractionPlanResolver;

/**
 * The evidence an output's edges are cut from, banked on the run: the dead-feed excision, where a
 * sermon beside a song ends (K16), the word timings at every edge the cut can reach (section edges,
 * the speech that ends each song, spoken ends beside songs, and edges the composition exposes), and
 * the sentence check asked of the cuts those words place; then the composition is re-composed from it.
 *
 * It neither changes the run's status nor cuts, so {@see TranscribeOutputEdges} calls it
 * inside a pipeline step and `historic-import:prepare-output-edges` calls it on a completed run.
 */
class PrepareOutputEdgeEvidence
{
    public function __construct(
        private readonly PrepareOutputEdgeWordTimings $prepare,
        private readonly SermonExtractionPlanResolver $resolver,
        private readonly CueSafeExtractionPlan $cutPlans,
        private readonly SpokenEdgeSentenceCheck $sentenceCheck,
        private readonly SermonSongIntroductionCheck $introductionCheck,
        private readonly DeadFeedExcision $deadFeedExcision,
        private readonly StorageAdapterHelper $storage,
    ) {}

    /** @return array<string, mixed> */
    public function prepare(MediaProcessingLog $run): array
    {
        $this->deadFeedExcision->prepare($run, $this->storage);
        $composition = $this->resolver->compose($run);
        $spans = [];
        foreach ($run->serviceSections()->orderBy('start_time')->get() as $section) {
            if (in_array($section->id, $composition['selected_section_ids'], true)
                || in_array($section->section_type->value, ['song', 'bible_reading', 'short_talk'], true)) {
                $spans[] = ['start_time' => (float) $section->start_time, 'end_time' => (float) $section->end_time];
            }
            if ($section->section_type === ServiceSectionType::Song) {
                foreach ($this->cutPlans->songEndSpeechEdges($run, [['start_time' => (float) $section->start_time, 'end_time' => (float) $section->end_time]]) as $edge) {
                    $spans[] = ['start_time' => $edge, 'end_time' => $edge];
                }
            }
        }
        $sermonSpans = array_values($run->serviceSections()->whereIn('id', $composition['selected_section_ids'])->orderBy('start_time')->get()
            ->map(static fn (ServiceSection $section): array => ['start_time' => (float) $section->start_time, 'end_time' => (float) $section->end_time])->all());
        // Where the sermon ends beside a song (K16) is answered first, so its words are decoded.
        $introduction = $this->introductionCheck->prepare($run, $sermonSpans);
        $run->refresh();
        $innerParts = [];
        foreach ($this->introductionCheck->innerParts($run, $sermonSpans) as $part) {
            $innerParts[$part] = $this->introductionCheck->prepare($run, $sermonSpans, $part);
            $run->refresh();
        }
        foreach ($this->cutPlans->spokenEndsBesideSongs($run, $sermonSpans) as $edge) {
            $spans[] = ['start_time' => $edge, 'end_time' => $edge];
        }
        $summary = ['song_introduction' => $introduction, ...($innerParts === [] ? [] : ['part_song_introductions' => $innerParts])] + $this->prepare->prepare($run->fresh() ?? throw new \RuntimeException('Processing run disappeared'), $spans);
        $run->refresh();
        $knownEdges = array_merge(...array_map(array_values(...), $spans));
        $exposed = $this->sentenceCheck->outputEdges($run, array_values($run->serviceSections()->orderBy('start_time')->get()->all()), $composition['selected_section_ids']);
        // A kept line its own decode did not hear is looked for at its later copies (1219).
        $copies = array_map(static fn (float $time): array => [null, $time], $this->cutPlans->spokenEndsBesideSongs($run, $sermonSpans));
        $followup = [];
        foreach ([...$exposed, ...$copies] as [, $time]) {
            if (! array_any($knownEdges, static fn (float $known): bool => abs($known - $time) < 0.001)) {
                $followup[] = ['start_time' => $time, 'end_time' => $time];
                $knownEdges[] = $time;
            }
        }
        if ($followup !== []) {
            $summary['exposed_edges'] = $this->prepare->prepare($run, $followup);
            $run->refresh();
        }
        $cues = app(OutputEdgeWordTimings::class)->cues($run);
        $dependent = app(DependentSongOpening::class);
        $windows = [];
        foreach ($run->serviceSections()->where('section_type', ServiceSectionType::Song)->orderBy('start_time')->get() as $section) {
            $plan = $this->cutPlans->forSpans($run, [['start_time' => (float) $section->start_time, 'end_time' => (float) $section->end_time]], songEnds: true);
            foreach ($plan['segments'] as $span) {
                $window = $dependent->window($run, $cues, (float) $span['start_time']);
                if ($window !== null) {
                    $evidence = app(OutputEdgeWordTimings::class);
                    $windows[$evidence->kind($evidence->identity($run, $window))] = $window;
                }
            }
        }
        if ($windows !== []) {
            $summary['dependent_openings'] = $this->prepare->prepareWindows($run, $windows);
            $run->refresh();
        }
        // Asked of the cuts the decoded words place, so only once they are decoded.
        $summary['sentence_check'] = $this->sentenceCheck->prepare($run, $composition['selected_section_ids']);
        $run->refresh();
        $this->resolver->compose($run);

        return $summary;
    }
}
